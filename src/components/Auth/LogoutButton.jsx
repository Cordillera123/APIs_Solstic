// src/components/Auth/LogoutButton.jsx
import React from 'react';
import { useNavigate } from 'react-router-dom';
import Icon from '../UI/Icon'; // Usando tu componente de ícono existente
import { useAuth } from '../../context/AuthContext';

const LogoutButton = ({ iconOnly = false }) => {
  const { logout } = useAuth();
  const navigate = useNavigate();
  
  const handleLogout = () => {
    logout();
    navigate('/login');
  };
  
  if (iconOnly) {
    return (
      <button
        onClick={handleLogout}
        className="p-1.5 rounded-full hover:bg-white hover:bg-opacity-20 transition-colors duration-150"
        title="Cerrar sesión"
      >
        <Icon name="LogOut" size={16} style={{ color: 'white' }} />
      </button>
    );
  }
  
  return (
    <button
      onClick={handleLogout}
      className="flex items-center px-3 py-2 text-sm rounded-md hover:bg-red-500 hover:text-white transition-colors duration-150"
    >
      <Icon name="LogOut" size={16} className="mr-2" />
      Cerrar Sesión
    </button>
  );
};

export default LogoutButton;