/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        // Nuevo esquema de colores profesional con celeste y azul marino
        'coop-primary': '#0ea5e9',    // Celeste (Sky-500) - Para elementos principales
        'coop-secondary': '#0369a1',  // Azul más oscuro (Sky-700) - Para elementos secundarios
        'coop-accent': '#0891b2',     // Turquesa (Cyan-600) - Para acentos
        'coop-dark': '#0c4a6e',       // Azul marino (Sky-900) - Para sidebar y fondos oscuros
        'coop-neutral': '#334155',    // Gris azulado (Slate-700) - Para textos principales
        'coop-light': '#f0f9ff',      // Celeste muy claro (Sky-50) - Para fondos claros
        'coop-success': '#10b981',    // Verde (Emerald-500) - Para éxito
        'coop-warning': '#f59e0b',    // Ámbar (Amber-500) - Para advertencias
        'coop-danger': '#ef4444',     // Rojo (Red-500) - Para errores/peligro
      },
      animation: {
        'spin-slow': 'spin 3s linear infinite',
        'fadeIn': 'fadeIn 0.2s ease-out forwards',
        'slideDown': 'slideDown 0.2s ease-out forwards',
        'pulse-subtle': 'pulseSubtle 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0', transform: 'scale(0.95)' },
          '100%': { opacity: '1', transform: 'scale(1)' },
        },
        slideDown: {
          '0%': { opacity: '0', transform: 'translateY(-10px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        pulseSubtle: {
          '0%, 100%': { opacity: 1 },
          '50%': { opacity: 0.85 },
        },
      },
      boxShadow: {
        'window': '0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
        'card-hover': '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)',
      },
      transitionProperty: {
        'height': 'height',
        'spacing': 'margin, padding',
      }
    },
  },
  plugins: [],
}