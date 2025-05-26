// src/services/authService.js - Actualizado para usar APIs reales
import { authService as apiAuth } from './apiService';

const AuthService = {
  // Iniciar sesión usando la API real
  login: async (email, password) => {
    try {
      const result = await apiAuth.login({ email, password });
      
      if (result.success) {
        // Los datos ya están guardados en localStorage por apiService
        return {
          id: result.data.user.id,
          username: result.data.user.email,
          fullName: result.data.user.nombre,
          email: result.data.user.email,
          cedula: result.data.user.cedula,
          perfil: result.data.user.perfil,
          estado: result.data.user.estado,
          token: result.data.access_token,
          permisos: result.data.permisos
        };
      } else {
        throw new Error(result.message || 'Error en la autenticación');
      }
    } catch (error) {
      console.error('Error en login:', error);
      throw new Error(error.message || 'Error de conexión con el servidor');
    }
  },
  
  // Cerrar sesión usando la API
  logout: async () => {
    try {
      await apiAuth.logout();
      // apiAuth.logout() ya limpia el localStorage
    } catch (error) {
      console.error('Error en logout:', error);
      // Limpiar localStorage aunque falle la API
      localStorage.removeItem('auth_token');
      localStorage.removeItem('user_data');
      localStorage.removeItem('user_permissions');
    }
  },
  
  // Verificar si hay una sesión activa
  isAuthenticated: () => {
    return apiAuth.isAuthenticated();
  },
  
  // Obtener usuario actual
  getCurrentUser: () => {
    const userData = apiAuth.getUserData();
    if (userData) {
      return {
        id: userData.id,
        username: userData.email,
        fullName: userData.nombre,
        email: userData.email,
        cedula: userData.cedula,
        perfil: userData.perfil,
        estado: userData.estado,
        permisos: apiAuth.getUserPermissions()
      };
    }
    return null;
  },

  // Obtener permisos del usuario
  getUserPermissions: () => {
    return apiAuth.getUserPermissions();
  },

  // Actualizar datos del usuario desde la API
  refreshUserData: async () => {
    try {
      const result = await apiAuth.getCurrentUser();
      if (result.success) {
        return result.data;
      }
      return null;
    } catch (error) {
      console.error('Error al actualizar datos del usuario:', error);
      return null;
    }
  }
};

export default AuthService;