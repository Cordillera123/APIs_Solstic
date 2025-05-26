// src/components/Auth/Login.jsx - Actualizado para usar email
import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, EyeOff, AlertCircle, ArrowRight } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';

const Login = () => {
  const [formData, setFormData] = useState({
    email: '', // Cambiado de username a email
    password: '',
  });
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);
  
  const navigate = useNavigate();
  const { login, isAuthenticated } = useAuth();

  // Verificar si ya está autenticado
  useEffect(() => {
    if (isAuthenticated) {
      navigate('/dashboard');
    }
  }, [isAuthenticated, navigate]);

  useEffect(() => {
    const savedUser = localStorage.getItem('rememberedUser');
    if (savedUser) {
      try {
        const userData = JSON.parse(savedUser);
        setFormData(prev => ({
          ...prev,
          email: userData.email || '' // Cambiado de username a email
        }));
        setRememberMe(true);
      } catch (error) {
        console.error('Error al cargar usuario recordado:', error);
      }
    }
  }, []);

  const handleChange = (e) => {
    setFormData({
      ...formData,
      [e.target.name]: e.target.value
    });
    
    if (error) setError('');
  };

  const validateForm = () => {
    if (!formData.email || !formData.password) {
      setError('Por favor ingrese su email y contraseña');
      return false;
    }
    
    // Validación básica de email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(formData.email)) {
      setError('Por favor ingrese un email válido');
      return false;
    }
    
    return true;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    console.log('Intentando iniciar sesión con:', formData.email);
    
    if (!validateForm()) {
      return;
    }
    
    setLoading(true);
    setError('');
    
    try {
      console.log('Llamando a la función login');
      // Pasar email y password directamente
      await login(formData.email, formData.password);
      console.log('Login exitoso');
      
      // Guardar credenciales si "recordar usuario" está activado
      if (rememberMe) {
        localStorage.setItem('rememberedUser', JSON.stringify({
          email: formData.email // Cambiado de username a email
        }));
      } else {
        localStorage.removeItem('rememberedUser');
      }
      
      console.log('Intentando navegar a /dashboard');
      navigate('/dashboard');
      console.log('Navegación iniciada');
      
    } catch (error) {
      console.error('Error durante el login:', error);
      
      // Manejar diferentes tipos de errores
      if (error.message.includes('Credenciales inválidas')) {
        setError('Email o contraseña incorrectos');
      } else if (error.message.includes('Usuario inactivo')) {
        setError('Su cuenta está inactiva. Contacte al administrador');
      } else if (error.message.includes('conexión')) {
        setError('Error de conexión. Verifique su internet');
      } else {
        setError(error.message || 'Error al iniciar sesión. Intente nuevamente.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center" style={{ backgroundColor: '#f0f9ff' }}>
      <div className="max-w-md w-full bg-white rounded-xl shadow-lg overflow-hidden">
        {/* Header con logo y degradado */}
        <div className="py-8 px-6 flex flex-col items-center" style={{ background: 'linear-gradient(to right, #0ea5e9, #0369a1)' }}>
          <div className="h-16 mb-2 flex items-center justify-center">
            <h1 className="text-2xl font-bold text-white tracking-tight">
              COAC SISTEMA
            </h1>
          </div>
          <h2 className="text-white opacity-90 text-sm mt-1">
            Sistema Financiero Integrado
          </h2>
        </div>
        
        {/* Formulario */}
        <div className="p-6">
          <h3 className="text-xl font-semibold mb-6" style={{ color: '#0c4a6e' }}>
            Iniciar Sesión
          </h3>
          
          {error && (
            <div className="bg-red-50 border-l-4 border-red-500 p-3 mb-6 flex items-start rounded">
              <AlertCircle size={18} className="text-red-500 mt-0.5 mr-2" />
              <p className="text-sm text-red-600">{error}</p>
            </div>
          )}
          
          <form onSubmit={handleSubmit}>
            <div className="mb-4">
              <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
                Email {/* Cambiado de "Usuario" a "Email" */}
              </label>
              <input
                type="email" // Cambiado de "text" a "email"
                id="email"
                name="email" // Cambiado de "username" a "email"
                className="w-full border border-gray-300 rounded-md py-2 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent"
                placeholder="Ingrese su email" // Actualizado placeholder
                value={formData.email} // Cambiado de username a email
                onChange={handleChange}
                autoComplete="email" // Cambiado de "username" a "email"
                disabled={loading}
              />
            </div>
            
            <div className="mb-4">
              <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
                Contraseña
              </label>
              <div className="relative">
                <input
                  type={showPassword ? "text" : "password"}
                  id="password"
                  name="password"
                  className="w-full border border-gray-300 rounded-md py-2 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent pr-10"
                  placeholder="Ingrese su contraseña"
                  value={formData.password}
                  onChange={handleChange}
                  autoComplete="current-password"
                  disabled={loading}
                />
                <button
                  type="button"
                  className="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                  onClick={() => setShowPassword(!showPassword)}
                  disabled={loading}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
            </div>

            {/* Checkbox "Recordar usuario" */}
            <div className="mb-4 flex items-center">
              <input
                type="checkbox"
                id="rememberMe"
                checked={rememberMe}
                onChange={(e) => setRememberMe(e.target.checked)}
                className="h-4 w-4 text-sky-600 focus:ring-sky-500 border-gray-300 rounded"
                disabled={loading}
              />
              <label htmlFor="rememberMe" className="ml-2 text-sm text-gray-700">
                Recordar mi email
              </label>
            </div>
            
            <button
              type="submit"
              className="w-full flex justify-center items-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-sky-500 hover:bg-sky-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500 disabled:opacity-50 disabled:cursor-not-allowed"
              disabled={loading}
              style={{ backgroundColor: loading ? '#93c5fd' : '#0ea5e9' }}
            >
              {loading ? (
                <svg className="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
              ) : (
                <>
                  Ingresar
                  <ArrowRight size={18} className="ml-1" />
                </>
              )}
            </button>
          </form>
        </div>
        
        {/* Footer */}
        <div className="px-6 py-4 bg-gray-50 text-center">
          <p className="text-xs text-gray-500">
            &copy; {new Date().getFullYear()} Cooperativa de Ahorro y Crédito. Todos los derechos reservados.
          </p>
          <p className="text-xs text-gray-400 mt-1">
            v1.0.0
          </p>
        </div>
      </div>
    </div>
  );
};

export default Login;