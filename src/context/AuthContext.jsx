// src/context/AuthContext.jsx - Actualizado para APIs reales
import React, { createContext, useContext, useState, useEffect } from 'react';
import AuthService from '../services/authService';

// Crear contexto
const AuthContext = createContext();

// Proveedor de autenticación
export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [permissions, setPermissions] = useState([]);
  
  // Cargar usuario desde localStorage al iniciar
  useEffect(() => {
    const loadUser = async () => {
      try {
        const currentUser = AuthService.getCurrentUser();
        if (currentUser) {
          setUser(currentUser);
          setPermissions(currentUser.permisos || []);
        }
      } catch (error) {
        console.error('Error al cargar usuario:', error);
        // Si hay error, limpiar datos
        AuthService.logout();
      } finally {
        setLoading(false);
      }
    };
    
    loadUser();
  }, []);
  
  // Función para iniciar sesión - ahora acepta email en lugar de username
  const login = async (email, password) => {
    try {
      setLoading(true);
      const userData = await AuthService.login(email, password);
      setUser(userData);
      setPermissions(userData.permisos || []);
      return userData;
    } catch (error) {
      console.error('Error en login:', error);
      throw error;
    } finally {
      setLoading(false);
    }
  };
  
  // Función para cerrar sesión
  const logout = async () => {
    try {
      setLoading(true);
      await AuthService.logout();
      setUser(null);
      setPermissions([]);
    } catch (error) {
      console.error('Error en logout:', error);
      // Limpiar estado aunque falle
      setUser(null);
      setPermissions([]);
    } finally {
      setLoading(false);
    }
  };

  // Función para actualizar datos del usuario
  const refreshUser = async () => {
    try {
      const updatedData = await AuthService.refreshUserData();
      if (updatedData) {
        const newUserData = {
          ...user,
          ...updatedData.user
        };
        setUser(newUserData);
        setPermissions(updatedData.permisos || []);
        return newUserData;
      }
    } catch (error) {
      console.error('Error al actualizar usuario:', error);
    }
    return null;
  };

  // Función para verificar si el usuario tiene un permiso específico
  const hasPermission = (menuId, submenuId = null, optionId = null) => {
    if (!permissions || permissions.length === 0) return false;
    
    return permissions.some(menu => {
      if (menu.men_id !== menuId) return false;
      
      if (!submenuId && !optionId) {
        return true; // Solo verificar menú principal
      }
      
      if (submenuId && !optionId) {
        // Verificar submenú
        return menu.submenus?.some(sub => sub.sub_id === submenuId);
      }
      
      if (submenuId && optionId) {
        // Verificar opción específica
        const submenu = menu.submenus?.find(sub => sub.sub_id === submenuId);
        return submenu?.opciones?.some(opt => opt.opc_id === optionId);
      }
      
      return false;
    });
  };

  // Función para obtener menús permitidos
  const getAllowedMenus = () => {
    return permissions || [];
  };
  
  // Valores del contexto
  const contextValue = {
    user,
    permissions,
    isAuthenticated: !!user,
    loading,
    login,
    logout,
    refreshUser,
    hasPermission,
    getAllowedMenus
  };
  
  return (
    <AuthContext.Provider value={contextValue}>
      {children}
    </AuthContext.Provider>
  );
};

// Hook personalizado para usar el contexto
export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth debe ser usado dentro de un AuthProvider');
  }
  return context;
};

export default AuthContext;