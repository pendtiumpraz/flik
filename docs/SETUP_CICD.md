# ⚙️ Aktifkan CI/CD Auto-Deploy (push main → auto deploy ke VM)

Workflow `.github/workflows/deploy.yml` sudah ada & otomatis trigger tiap push ke `main`,
tapi butuh **3 langkah setup sekali** di bawah supaya berfungsi.

Nilai VM-mu:
- VM_HOST: `34.50.66.29`
- VM_USER: `dobeon_com`

---

## Langkah 1 — SSH key + sudo (jalankan DI VM, satu per satu)

```bash
ssh-keygen -t ed25519 -f ~/deploy_key -N ""
```
```bash
cat ~/deploy_key.pub >> ~/.ssh/authorized_keys
```
```bash
echo "$USER ALL=(ALL) NOPASSWD:ALL" | sudo tee /etc/sudoers.d/flik-deploy
```
```bash
cat ~/deploy_key
```

> Perintah terakhir menampilkan **PRIVATE key**. Copy **SEMUA** isinya — dari baris
> `-----BEGIN OPENSSH PRIVATE KEY-----` sampai `-----END OPENSSH PRIVATE KEY-----`.

---

## Langkah 2 — Set Secrets di GitHub

Repo GitHub → **Settings → Environments → New environment** → ketik **`production`** → **Configure**
→ klik **Add secret**, masukkan 4 ini:

| Name | Value |
|---|---|
| `VM_HOST` | `34.50.66.29` |
| `VM_USER` | `dobeon_com` |
| `VM_SSH_KEY` | (paste private key dari Langkah 1) |
| `DISCORD_WEBHOOK` | *(opsional — skip dulu juga boleh)* |

> Penting: bikin **Environment** bernama `production` (bukan repo-secret biasa), karena
> workflow memilih environment by branch (`main` → `production`).

---

## Langkah 3 — Tes

Repo → **Actions → "Deploy to VM" → Run workflow → branch `main` → Run workflow.**

- **Hijau ✅** → CI/CD nyala! Mulai sekarang tiap `git push origin main` otomatis:
  `git pull → backup DB → migrate → build → health-check → rollback kalau gagal → notif Discord`.
- **Merah ❌** → klik run → buka step **"Deploy over SSH"** → baca/paste error-nya.

---

## Troubleshooting cepat

| Gejala | Penyebab / fix |
|---|---|
| `Permission denied (publickey)` | Public key belum masuk `~/.ssh/authorized_keys` VM, atau `VM_SSH_KEY` salah copy (harus lengkap dgn baris BEGIN/END) |
| `sudo: a password is required` | `/etc/sudoers.d/flik-deploy` belum dibuat (Langkah 1 baris ke-3) |
| `Host key verification` / timeout | `VM_HOST` salah, atau firewall port 22 ketutup |
| Workflow gak jalan sama sekali | Secrets ditaruh di environment yang salah (harus `production`) |

---

## Cara kerja setelah aktif

```
git push origin main
      ↓
GitHub Actions (environment: production)
      ↓ SSH ke 34.50.66.29
git reset --hard origin/main
      ↓
scripts/deploy.sh:
  • backup DB (pre-migrate, lokal + GCS)
  • composer install --no-dev
  • npm run build
  • php artisan migrate --force
  • php artisan optimize
  • queue:restart + reload php-fpm
      ↓
health-check GET /healthz
  ✅ sehat  → selesai + notif Discord hijau
  ❌ gagal  → git reset balik ke commit sebelumnya (rollback) + notif merah
```

Branch `staging` → environment `staging` (VM/DB terpisah) — lihat `deploy-gcp.md §12`.
