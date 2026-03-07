const defaultTheme = require('tailwindcss/defaultTheme');

module.exports = {
    darkMode: 'class',
    content: ['./resources/**/*.blade.php', './resources/**/*.js', './resources/**/*.vue'],
    theme: {
        extend: {
            fontFamily: {
                heading: ['Outfit', ...defaultTheme.fontFamily.sans],
                body: ['Inter', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                gold: {
                    50:  '#FDF8E8',
                    100: '#F9EEC5',
                    200: '#F0D78C',
                    300: '#E5C060',
                    400: '#D4B96E',
                    500: '#C5A55A',
                    600: '#A88B3E',
                    700: '#8B7340',
                    800: '#6E5A30',
                    900: '#8B6914',
                    950: '#3D2E0A',
                },
                flik: {
                    // Dark mode backgrounds
                    'bg-primary':    '#0A0A0A',
                    'bg-secondary':  '#141414',
                    'bg-tertiary':   '#1E1E1E',
                    'surface':       '#252525',
                    // Light mode backgrounds
                    'light-primary':   '#FAFAFA',
                    'light-secondary': '#F0F0F0',
                    'light-tertiary':  '#E8E8E8',
                    'light-surface':   '#FFFFFF',
                },
            },
            backgroundImage: {
                'gold-gradient': 'linear-gradient(135deg, #F0D78C, #8B6914)',
                'gold-shine': 'linear-gradient(135deg, #F0D78C, #C5A55A)',
                'gold-radial': 'radial-gradient(ellipse at top, #F0D78C, #8B6914)',
            },
            borderRadius: {
                'sm': '6px',
                'md': '10px',
                'lg': '16px',
                'xl': '24px',
            },
            boxShadow: {
                'card': '0 4px 24px rgba(0, 0, 0, 0.3)',
                'card-hover': '0 8px 40px rgba(0, 0, 0, 0.5)',
                'gold-glow': '0 0 20px rgba(197, 165, 90, 0.3)',
                'gold-glow-strong': '0 0 40px rgba(197, 165, 90, 0.5)',
            },
            animation: {
                'fade-in': 'fadeIn 0.5s ease-out',
                'slide-up': 'slideUp 0.5s ease-out',
                'slide-down': 'slideDown 0.3s ease-out',
                'scale-in': 'scaleIn 0.3s ease-out',
                'shimmer': 'shimmer 2s infinite linear',
                'gold-pulse': 'goldPulse 2s infinite',
            },
            keyframes: {
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                slideUp: {
                    '0%': { opacity: '0', transform: 'translateY(20px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                slideDown: {
                    '0%': { opacity: '0', transform: 'translateY(-10px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                scaleIn: {
                    '0%': { opacity: '0', transform: 'scale(0.95)' },
                    '100%': { opacity: '1', transform: 'scale(1)' },
                },
                shimmer: {
                    '0%': { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
                goldPulse: {
                    '0%, 100%': { boxShadow: '0 0 10px rgba(197, 165, 90, 0.2)' },
                    '50%': { boxShadow: '0 0 30px rgba(197, 165, 90, 0.5)' },
                },
            },
        },
    },
    plugins: [],
}
