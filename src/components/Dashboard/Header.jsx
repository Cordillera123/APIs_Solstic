// Archivo: Header.jsx
import React, { useState } from 'react';
import { Bell, Search, HelpCircle, Menu, User } from 'lucide-react';

const Header = ({ title, subtitle, activeTab, moduleNames }) => {
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  
  // Encontrar el nombre del módulo activo
  const getActiveModuleName = () => {
    if (!activeTab || !moduleNames) return '';
    const module = moduleNames.find(m => m.id === activeTab);
    return module ? module.name : '';
  };
  
  return (
    <header className="bg-white shadow-md flex items-center justify-between p-3 z-10">
      <div className="flex items-center">
        <div className="mr-8">
          <div className="flex flex-col">
            <h1 className="text-xl font-bold text-coop-primary tracking-tight">{title}</h1>
            <div className="text-sm text-coop-neutral opacity-70">{subtitle}</div>
          </div>
        </div>
        
        {activeTab && (
          <div className="px-3 py-1 rounded-md bg-coop-primary text-white text-sm font-medium ml-2">
            {getActiveModuleName()}
          </div>
        )}
      </div>
      
      <div className="flex items-center space-x-4">
        <div className="relative">
          <div className="flex items-center bg-coop-light rounded-full pr-2 focus-within:ring-2 focus-within:ring-coop-primary">
            <input 
              type="text" 
              placeholder="Buscar..." 
              className="py-1.5 px-4 pl-8 text-sm bg-transparent outline-none w-48"
            />
            <Search className="absolute left-2 top-1.5 text-coop-neutral opacity-60" size={16} />
          </div>
        </div>
        
        <div className="flex space-x-2">
          <button className="relative p-2 text-coop-neutral opacity-80 hover:opacity-100 hover:bg-coop-light rounded-full transition-all duration-150">
            <HelpCircle size={18} />
          </button>
          
          <div className="relative">
            <button 
              className="relative p-2 text-coop-neutral opacity-80 hover:opacity-100 hover:bg-coop-light rounded-full transition-all duration-150"
              onClick={() => setNotificationsOpen(!notificationsOpen)}
            >
              <Bell size={18} />
              <span className="absolute top-0 right-0 w-2 h-2 bg-coop-accent rounded-full"></span>
            </button>
            
            {notificationsOpen && (
              <div className="absolute right-0 mt-1 w-72 bg-white rounded-lg shadow-lg py-2 z-20 animate-fadeIn">
                <div className="px-4 py-2 border-b border-gray-100">
                  <h3 className="font-semibold text-coop-dark">Notificaciones</h3>
                </div>
                <div className="max-h-72 overflow-y-auto">
                  <div className="px-4 py-3 hover:bg-coop-light border-l-4 border-coop-primary">
                    <div className="font-medium text-sm">Nueva transacción</div>
                    <div className="text-xs text-coop-neutral opacity-70 mt-1">Hace 5 minutos</div>
                  </div>
                  <div className="px-4 py-3 hover:bg-coop-light">
                    <div className="font-medium text-sm">Actualización del sistema</div>
                    <div className="text-xs text-coop-neutral opacity-70 mt-1">Hace 2 horas</div>
                  </div>
                </div>
                <div className="px-4 py-2 border-t border-gray-100 text-center">
                  <button className="text-xs text-coop-secondary hover:underline">Ver todas</button>
                </div>
              </div>
            )}
          </div>
          
          <button className="p-2 text-coop-neutral opacity-80 hover:opacity-100 hover:bg-coop-light rounded-full transition-all duration-150 lg:hidden">
            <Menu size={18} />
          </button>
        </div>
      </div>
    </header>
  );
};

export default Header;