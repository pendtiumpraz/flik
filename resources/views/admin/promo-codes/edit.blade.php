<x-admin.layout title="Edit Promo Code">
    @include('admin.promo-codes._form', [
        'action'    => route('admin.promo-codes.update', $promoCode),
        'method'    => 'PUT',
        'promoCode' => $promoCode,
        'plans'     => $plans,
        'types'     => $types,
        'submit'    => 'Save Changes',
    ])
</x-admin.layout>
