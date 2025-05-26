// src/components/Dashboard/Sidebar.jsx - CÓDIGO COMPLETO CON ICONOS ARREGLADOS
import React, { useState, useEffect } from "react";
import { ChevronDown, ChevronRight, HelpCircle, Info } from "lucide-react";
import { useAuth } from '../../context/AuthContext';
import { menuService } from '../../services/apiService';
import LogoutButton from '../Auth/LogoutButton';
import Icon from "../UI/Icon";

const Sidebar = ({
  onOpenWindow, // Función para abrir ventanas desde el Dashboard
  currentDate,
}) => {
  const { user, getAllowedMenus, hasPermission } = useAuth();
  
  // Estados para el menú dinámico
  const [menuData, setMenuData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [expandedMenus, setExpandedMenus] = useState(new Set());
  const [expandedSubmenus, setExpandedSubmenus] = useState(new Set());

  // Cargar menús del usuario al montar el componente
  useEffect(() => {
    loadUserMenu();
  }, []);

  const loadUserMenu = async () => {
    try {
      setLoading(true);
      setError('');
      
      const result = await menuService.getUserMenu();
      
      if (result.success) {
        console.log('📋 Menú cargado:', result.data.menus);
        setMenuData(result.data.menus || []);
      } else {
        setError(result.message || 'Error al cargar el menú');
        console.error('Error loading menu:', result);
      }
    } catch (error) {
      console.error('Error loading menu:', error);
      setError('Error de conexión al cargar el menú');
    } finally {
      setLoading(false);
    }
  };

  // Manejar expansión de menús principales
  const toggleMenu = (menuId) => {
    const newExpanded = new Set(expandedMenus);
    if (newExpanded.has(menuId)) {
      newExpanded.delete(menuId);
      // También cerrar todos los submenús de este menú
      const relatedSubmenus = menuData.find(m => m.men_id === menuId)?.submenus || [];
      relatedSubmenus.forEach(sub => {
        const newSubExpanded = new Set(expandedSubmenus);
        newSubExpanded.delete(sub.sub_id);
        setExpandedSubmenus(newSubExpanded);
      });
    } else {
      newExpanded.add(menuId);
    }
    setExpandedMenus(newExpanded);
  };

  // Manejar expansión de submenús
  const toggleSubmenu = (submenuId) => {
    const newExpanded = new Set(expandedSubmenus);
    if (newExpanded.has(submenuId)) {
      newExpanded.delete(submenuId);
    } else {
      newExpanded.add(submenuId);
    }
    setExpandedSubmenus(newExpanded);
  };

  // Manejar clic en elementos del menú
  const handleMenuClick = (menu) => {
    console.log('Menu clicked:', menu);
    
    // Si el menú tiene ventana directa, abrir como ventana
    if (menu.men_ventana_directa && menu.men_componente) {
      console.log('Opening direct window with component:', menu.men_componente);
      onOpenWindow({
        id: `menu-${menu.men_id}`,
        title: menu.men_nom,
        component: menu.men_componente,
        type: 'menu',
        data: menu,
      });
      return;
    }

    // Si tiene submenús, expandir/contraer
    if (menu.submenus && menu.submenus.length > 0) {
      toggleMenu(menu.men_id);
    } else {
      // Si no tiene submenús ni ventana directa, abrir ventana por defecto
      onOpenWindow({
        id: `menu-${menu.men_id}`,
        title: menu.men_nom,
        component: 'DefaultWindow',
        type: 'menu',
        data: menu,
      });
    }
  };

  const handleSubmenuClick = (submenu, parentMenu) => {
    console.log('Submenu clicked:', submenu);
    
    // Si el submenú tiene ventana directa, abrir como ventana
    if (submenu.sub_ventana_directa && submenu.sub_componente) {
      console.log('Opening submenu direct window:', submenu.sub_componente);
      onOpenWindow({
        id: `submenu-${submenu.sub_id}`,
        title: `${parentMenu.men_nom} > ${submenu.sub_nom}`,
        component: submenu.sub_componente,
        type: 'submenu',
        data: {
          submenu,
          parentMenu,
        },
      });
      return;
    }

    // Si tiene opciones, expandir/contraer
    if (submenu.opciones && submenu.opciones.length > 0) {
      toggleSubmenu(submenu.sub_id);
    } else {
      // Si no tiene opciones ni ventana directa, abrir ventana por defecto
      onOpenWindow({
        id: `submenu-${submenu.sub_id}`,
        title: `${parentMenu.men_nom} > ${submenu.sub_nom}`,
        component: 'DefaultWindow',
        type: 'submenu',
        data: {
          submenu,
          parentMenu,
        },
      });
    }
  };

  const handleOptionClick = (option, parentSubmenu, parentMenu) => {
    console.log('Option clicked:', option);
    
    // Las opciones siempre abren como ventanas
    onOpenWindow({
      id: `option-${option.opc_id}`,
      title: `${parentMenu.men_nom} > ${parentSubmenu.sub_nom} > ${option.opc_nom}`,
      component: option.opc_componente || 'DefaultWindow',
      type: 'option',
      data: {
        option,
        parentSubmenu,
        parentMenu,
      },
    });
  };

  // Estilos
  const sidebarStyle = {
    width: "16rem",
    backgroundColor: "#0c4a6e",
    color: "white",
    display: "flex",
    flexDirection: "column",
    boxShadow: "0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)",
  };

  const headerStyle = {
    padding: "1rem",
    background: "linear-gradient(to right, #0ea5e9, #0369a1)",
    display: "flex",
    flexDirection: "column",
    alignItems: "center",
    justifyContent: "center",
    paddingTop: "1.5rem",
    paddingBottom: "1.5rem",
    borderBottom: "1px solid rgba(255, 255, 255, 0.1)",
  };

  const logoStyle = {
    fontSize: "1.25rem",
    fontWeight: "bold",
    letterSpacing: "-0.025em",
    marginBottom: "0.25rem",
    color: "white",
    textShadow: "0 1px 2px rgba(0, 0, 0, 0.1)",
  };

  const dateStyle = {
    fontSize: "0.75rem",
    color: "rgba(255, 255, 255, 0.9)",
    fontWeight: "500",
  };

  const sectionTitleStyle = {
    paddingLeft: "0.75rem",
    paddingRight: "0.75rem",
    paddingTop: "0.5rem",
    paddingBottom: "0.5rem",
    fontSize: "0.75rem",
    fontWeight: "600",
    color: "rgba(255, 255, 255, 0.8)",
    textTransform: "uppercase",
    letterSpacing: "0.05em",
    marginTop: "1rem",
  };

  const profileContainerStyle = {
    padding: "0.75rem",
    borderTop: "1px solid rgba(255, 255, 255, 0.1)",
    backgroundColor: "rgba(12, 74, 110, 0.9)",
  };

  const profileAvatarStyle = {
    width: "2.5rem",
    height: "2.5rem",
    borderRadius: "9999px",
    background: "linear-gradient(to right, #0ea5e9, #0369a1)",
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
    color: "white",
    fontWeight: "bold",
    boxShadow: "0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06)",
  };

  // Renderizar contenido de carga
  if (loading) {
    return (
      <div style={sidebarStyle}>
        <div style={headerStyle}>
          <div style={logoStyle}>COAC SISTEMA</div>
          <div style={dateStyle}>{currentDate}</div>
        </div>
        
        <div className="flex-1 p-4">
          <div className="animate-pulse space-y-4">
            <div className="h-4 bg-gray-600 rounded w-3/4"></div>
            <div className="h-4 bg-gray-600 rounded w-1/2"></div>
            <div className="h-4 bg-gray-600 rounded w-2/3"></div>
          </div>
        </div>
      </div>
    );
  }

  // Renderizar error
  if (error) {
    return (
      <div style={sidebarStyle}>
        <div style={headerStyle}>
          <div style={logoStyle}>COAC SISTEMA</div>
          <div style={dateStyle}>{currentDate}</div>
        </div>
        
        <div className="flex-1 p-4">
          <div className="text-red-400 text-sm mb-2">Error: {error}</div>
          <button 
            onClick={loadUserMenu}
            className="text-blue-400 text-sm hover:text-blue-300 underline"
          >
            Reintentar
          </button>
        </div>
      </div>
    );
  }

  return (
    <div style={sidebarStyle}>
      {/* Logo y encabezado */}
      <div style={headerStyle}>
        <div style={logoStyle}>COAC SISTEMA</div>
        <div style={dateStyle}>{currentDate}</div>
      </div>

      {/* Módulos dinámicos desde la API */}
      <div className="flex-1 overflow-y-auto py-2">
        <div style={sectionTitleStyle}>
          Módulos del Sistema
        </div>
        
        <ul className="mt-2 space-y-1 px-2">
          {menuData.map((menu) => (
            <li key={menu.men_id}>
              {/* Menú principal */}
              <div
                className={`flex items-center p-2 rounded-md cursor-pointer transition-all duration-150 ${
                  expandedMenus.has(menu.men_id)
                    ? "bg-coop-primary bg-opacity-70 text-white"
                    : "hover:bg-[#3b82f640]"
                }`}
                onClick={() => handleMenuClick(menu)}
              >
                <div className="p-1.5 rounded-md bg-coop-primary bg-opacity-30">
                  <Icon 
                    name={menu.ico_nombre || 'Circle'} 
                    size={16} 
                    className="text-white" 
                  />
                </div>
                <span className="ml-3 text-sm font-medium text-white">
                  {menu.men_nom}
                </span>
                {menu.submenus && menu.submenus.length > 0 && (
                  <div className="ml-auto">
                    {expandedMenus.has(menu.men_id) ? (
                      <ChevronDown size={16} className="text-white" />
                    ) : (
                      <ChevronRight size={16} className="text-white" />
                    )}
                  </div>
                )}
              </div>

              {/* Submenús */}
              {expandedMenus.has(menu.men_id) && menu.submenus && (
                <ul className="pl-10 mt-1 space-y-1">
                  {menu.submenus.map((submenu) => (
                    <li key={submenu.sub_id}>
                      {/* Submenú */}
                      <div
                        className={`flex items-center p-1.5 rounded-md cursor-pointer transition-all duration-150 ${
                          expandedSubmenus.has(submenu.sub_id)
                            ? "bg-coop-primary bg-opacity-50 text-white"
                            : "hover:bg-[#3b82f640]"
                        }`}
                        onClick={() => handleSubmenuClick(submenu, menu)}
                      >
                        <div className="w-1.5 h-1.5 rounded-full bg-white mr-2"></div>
                        <Icon 
                          name={submenu.ico_nombre || 'Circle'} 
                          size={12} 
                          className="text-white mr-2" 
                        />
                        <span className="text-sm text-white">{submenu.sub_nom}</span>
                        {submenu.opciones && submenu.opciones.length > 0 && (
                          <div className="ml-auto">
                            {expandedSubmenus.has(submenu.sub_id) ? (
                              <ChevronDown size={14} className="text-white" />
                            ) : (
                              <ChevronRight size={14} className="text-white" />
                            )}
                          </div>
                        )}
                      </div>

                      {/* Opciones */}
                      {expandedSubmenus.has(submenu.sub_id) && submenu.opciones && (
                        <ul className="pl-8 mt-1 space-y-1">
                          {submenu.opciones.map((option) => (
                            <li key={option.opc_id}>
                              <div
                                className="flex items-center p-1 rounded-md cursor-pointer transition-all duration-150 hover:bg-[#3b82f620] text-gray-300"
                                onClick={() => handleOptionClick(option, submenu, menu)}
                              >
                                <Icon 
                                  name={option.ico_nombre || 'Circle'} 
                                  size={12} 
                                  className="mr-2 text-gray-300" 
                                />
                                <span className="text-xs">{option.opc_nom}</span>
                              </div>
                            </li>
                          ))}
                        </ul>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>

        {/* Mensaje si no hay menús */}
        {menuData.length === 0 && !loading && !error && (
          <div className="px-4 py-8 text-center">
            <Icon name="AlertCircle" size={32} className="mx-auto mb-2 text-gray-400" />
            <p className="text-sm text-gray-400">No hay módulos disponibles</p>
            <button 
              onClick={loadUserMenu}
              className="text-blue-400 text-xs hover:text-blue-300 underline mt-2"
            >
              Recargar
            </button>
          </div>
        )}

        {/* Sección de información */}
        <div style={sectionTitleStyle}>
          Información
        </div>
        <ul className="mt-2 space-y-1 px-2">
          <li className="flex items-center p-2 rounded-md cursor-pointer hover:bg-white hover:bg-opacity-10 transition-all duration-150 text-white">
            <div className="p-1.5 rounded-md bg-coop-primary bg-opacity-30">
              <HelpCircle size={18} className="text-white" />
            </div>
            <span className="ml-3 text-sm font-medium">Ayuda</span>
          </li>
          <li className="flex items-center p-2 rounded-md cursor-pointer hover:bg-white hover:bg-opacity-10 transition-all duration-150 text-white">
            <div className="p-1.5 rounded-md bg-coop-primary bg-opacity-30">
              <Info size={18} className="text-white" />
            </div>
            <span className="ml-3 text-sm font-medium">Acerca de</span>
          </li>
        </ul>
      </div>

      {/* Perfil de usuario */}
      <div style={profileContainerStyle}>
        <div className="flex items-center p-2">
          <div style={profileAvatarStyle}>
            {user?.fullName 
              ? user.fullName.split(' ').map(n => n[0]).join('').toUpperCase() 
              : user?.email?.substring(0, 2).toUpperCase() || 'UA'}
          </div>
          <div className="ml-3">
            <div className="font-medium text-sm text-white">
              {user?.fullName || user?.email || 'Usuario Admin'}
            </div>
            <div className="text-xs text-white text-opacity-90 flex items-center">
              <div className="w-2 h-2 rounded-full bg-green-500 mr-1.5"></div>
              Conectado - {user?.perfil || 'Sin perfil'}
            </div>
          </div>
          <div className="ml-auto">
            <LogoutButton iconOnly={true} />
          </div>
        </div>
      </div>
    </div>
  );
};

export default Sidebar;