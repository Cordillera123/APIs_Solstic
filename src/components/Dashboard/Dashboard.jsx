// src/components/Dashboard/Dashboard.jsx - Actualizado para integrar APIs
import React, { useState, useEffect, useCallback } from "react";
import { ChevronLeft, ChevronRight, Grid, Layout, X, Layers } from "lucide-react";

import Sidebar from "./Sidebar";
import WindowPanel from "./WindowPanel";
import useWindows from "../../hooks/useWindows";
import { useAuth } from '../../context/AuthContext';
import { getComponent, getWindowConfig, mapApiDataToProps } from '../../config/componentMapping.jsx';
import Icon from "../UI/Icon";

const Dashboard = () => {
  const { user, isAuthenticated, loading: authLoading } = useAuth();
  
  // Estados para paginación y gestión de ventanas
  const [currentPage, setCurrentPage] = useState(0);
  const WINDOWS_PER_PAGE = 4;

  // Hook personalizado para gestión de ventanas
  const {
    windows,
    activeWindowId,
    errorMessage,
    minimizedWindows,
    openWindow,
    closeWindow,
    setActiveWindow,
    updateWindowPosition,
    updateWindowSize,
    minimizeWindow,
    restoreWindow,
    toggleMaximize,
    cascadeWindows,
    closeAllWindows,
    MAX_WINDOWS,
  } = useWindows();

  // Redirigir si no está autenticado
  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      window.location.href = '/login';
    }
  }, [isAuthenticated, authLoading]);

  // Obtener fecha actual formateada
  const getCurrentDate = () => {
    const today = new Date();
    const options = {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
    };
    return today.toLocaleDateString("es-ES", options);
  };

  const currentDate = getCurrentDate();

  // Filtrar ventanas visibles (no minimizadas)
  const visibleWindows = windows.filter((w) => !minimizedWindows.includes(w.id));

  // Calcular número total de páginas
  const totalPages = Math.ceil(visibleWindows.length / WINDOWS_PER_PAGE);

  // Obtener ventanas para la página actual
  const currentPageWindows = visibleWindows.slice(
    currentPage * WINDOWS_PER_PAGE,
    (currentPage + 1) * WINDOWS_PER_PAGE
  );

  // Funciones de paginación
  const nextPage = () => {
    if (currentPage < totalPages - 1) {
      setCurrentPage(currentPage + 1);
    }
  };

  const prevPage = () => {
    if (currentPage > 0) {
      setCurrentPage(currentPage - 1);
    }
  };

  // Verificar si no hay ventanas abiertas
  const isEmpty = windows.length === 0;

  // Función para organizar las ventanas en mosaico
  const arrangeTileLayout = useCallback(() => {
    const pageWindows = visibleWindows.slice(
      currentPage * WINDOWS_PER_PAGE,
      (currentPage + 1) * WINDOWS_PER_PAGE
    );
    if (pageWindows.length === 0) return;

    let cols, rows;
    switch (pageWindows.length) {
      case 1:
        cols = 1;
        rows = 1;
        break;
      case 2:
        cols = 2;
        rows = 1;
        break;
      case 3:
        cols = 3;
        rows = 1;
        break;
      case 4:
      default:
        cols = 2;
        rows = 2;
        break;
    }

    const mainAreaEl = document.querySelector(".main-area");
    if (!mainAreaEl) return;

    const mainAreaWidth = mainAreaEl.clientWidth;
    const mainAreaHeight = mainAreaEl.clientHeight;

    const margin = 16;
    const windowWidth = Math.floor((mainAreaWidth - margin * (cols + 1)) / cols);
    const windowHeight = Math.floor((mainAreaHeight - margin * (rows + 1)) / rows);

    pageWindows.forEach((window, index) => {
      const row = Math.floor(index / cols);
      const col = index % cols;
      const x = margin + col * (windowWidth + margin);
      const y = margin + row * (windowHeight + margin);

      updateWindowPosition(window.id, x, y);
      updateWindowSize(window.id, windowWidth, windowHeight);
    });
  }, [visibleWindows, currentPage, updateWindowPosition, updateWindowSize]);
  
  // Manejar apertura de ventanas desde el Sidebar
  const handleOpenWindow = (windowConfig) => {
     console.log('handleOpenWindow called with:', windowConfig);
    const { id, title, component, type, data } = windowConfig;
    console.log('Component name from config:', component); 
    // Obtener configuración de la ventana
    const config = getWindowConfig(component);
    
    // Mapear datos de la API a props del componente
    let mappedProps = {};
    if (type === 'menu' && data) {
      mappedProps = mapApiDataToProps(data);
    } else if (type === 'submenu' && data) {
      mappedProps = mapApiDataToProps(data.parentMenu, data.submenu);
    } else if (type === 'option' && data) {
      mappedProps = mapApiDataToProps(data.parentMenu, data.parentSubmenu, data.option);
    }
    
    // Abrir la ventana
    const result = openWindow(
    id,           // windowId 
    null,         // subModuleId (no usado en este contexto)
    title,        // moduleName
    'Monitor',    // icon (usar component como referencia)
    title,
    component        // subModuleName
  );

    // Organizar en mosaico inmediatamente después de abrir
    if (result && result.success) {
    setTimeout(() => {
      arrangeTileLayout();
    }, 100);
  }
};

  // Restaurar una ventana minimizada y reordenar
  const handleRestoreWindow = (windowId) => {
    restoreWindow(windowId);
    setTimeout(() => arrangeTileLayout(), 50);
  };

  // Organizar ventanas en mosaico automáticamente cuando cambian
  useEffect(() => {
    if (visibleWindows.length > 0) {
      const delay = setTimeout(() => {
        arrangeTileLayout();
      }, 100);
      return () => clearTimeout(delay);
    }
  }, [arrangeTileLayout, visibleWindows.length, currentPage]);

  // Estilos para la barra de herramientas
  const toolbarStyles = {
    container: {
      position: "fixed",
      top: "5px",
      right: "250px",
      display: "flex",
      backgroundColor: "white",
      borderRadius: "0.375rem",
      boxShadow: "0 2px 5px -1px rgba(0, 0, 0, 0.1), 0 1px 3px -1px rgba(0, 0, 0, 0.05)",
      zIndex: 30,
      border: "1px solid #e2e8f0",
    },
    button: {
      padding: "0.5rem",
      backgroundColor: "transparent",
      border: "none",
      borderRadius: "0.375rem",
      display: "flex",
      alignItems: "center",
      justifyContent: "center",
      cursor: "pointer",
      transition: "background-color 150ms",
      color: "#475569",
    },
    separator: {
      width: "1px",
      margin: "0.5rem 0",
      backgroundColor: "#e2e8f0",
    },
  };

  // Mostrar loading si la autenticación está cargando
  if (authLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-100">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
          <p className="mt-4 text-gray-600">Cargando...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex h-screen bg-coop-light overflow-hidden" style={{ backgroundColor: "#f0f9ff" }}>
      {/* Sidebar */}
      <Sidebar
        onOpenWindow={handleOpenWindow}
        currentDate={currentDate}
      />

      {/* Área principal */}
      <div className="flex-1 relative overflow-hidden main-area">
        {/* Barra de herramientas */}
        {visibleWindows.length > 0 && (
          <div style={toolbarStyles.container}>
            <button
              style={toolbarStyles.button}
              title="Organizar en mosaico"
              onClick={arrangeTileLayout}
              onMouseEnter={(e) => e.currentTarget.style.backgroundColor = "#f1f5f9"}
              onMouseLeave={(e) => e.currentTarget.style.backgroundColor = "transparent"}
            >
              <Grid size={18} />
            </button>
            <div style={toolbarStyles.separator}></div>
            <button
              style={toolbarStyles.button}
              title="Organizar en cascada"
              onClick={cascadeWindows}
              onMouseEnter={(e) => e.currentTarget.style.backgroundColor = "#f1f5f9"}
              onMouseLeave={(e) => e.currentTarget.style.backgroundColor = "transparent"}
            >
              <Layers size={18} />
            </button>
            <div style={toolbarStyles.separator}></div>
            <button
              style={toolbarStyles.button}
              title="Cerrar todas las ventanas"
              onClick={closeAllWindows}
              onMouseEnter={(e) => e.currentTarget.style.backgroundColor = "#f1f5f9"}
              onMouseLeave={(e) => e.currentTarget.style.backgroundColor = "transparent"}
            >
              <X size={18} />
            </button>
          </div>
        )}

        {/* Contenido cuando no hay ventanas */}
        {isEmpty && (
          <div className="flex flex-col h-full p-8 bg-gray-50 overflow-auto">
            {/* Panel de control */}
            <div className="text-center mb-8">
              <h1 className="text-3xl font-bold text-coop-dark mb-2">
                Panel de Control
              </h1>
              <p className="text-gray-600 max-w-2xl mx-auto">
                Bienvenido al sistema financiero integrado. 
                Use el menú lateral para navegar entre los diferentes módulos.
              </p>
            </div>

            {/* Información del usuario */}
            {user && (
              <div className="max-w-4xl mx-auto w-full mb-8">
                <div className="bg-white rounded-lg shadow-sm p-6 border border-gray-200">
                  <h2 className="text-lg font-semibold text-coop-dark mb-4">
                    Información de la Sesión
                  </h2>
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                      <label className="text-sm font-medium text-gray-500">Usuario</label>
                      <p className="text-gray-900">{user.fullName}</p>
                    </div>
                    <div>
                      <label className="text-sm font-medium text-gray-500">Email</label>
                      <p className="text-gray-900">{user.email}</p>
                    </div>
                    <div>
                      <label className="text-sm font-medium text-gray-500">Perfil</label>
                      <p className="text-gray-900">{user.perfil || 'No asignado'}</p>
                    </div>
                    {user.cedula && (
                      <div>
                        <label className="text-sm font-medium text-gray-500">Cédula</label>
                        <p className="text-gray-900">{user.cedula}</p>
                      </div>
                    )}
                    <div>
                      <label className="text-sm font-medium text-gray-500">Estado</label>
                      <p className="text-gray-900">{user.estado || 'Activo'}</p>
                    </div>
                    <div>
                      <label className="text-sm font-medium text-gray-500">Permisos</label>
                      <p className="text-gray-900">{user.permisos?.length || 0} módulos</p>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* Widgets de resumen */}
            <div className="max-w-4xl mx-auto w-full grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              <div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow">
                <div className="flex items-center mb-4">
                  <div className="bg-blue-100 p-3 rounded-full mr-4">
                    <Icon name="Users" size={24} className="text-blue-600" />
                  </div>
                  <h3 className="font-semibold text-coop-dark">Clientes Activos</h3>
                </div>
                <div className="flex items-end justify-between">
                  <p className="text-2xl font-bold text-coop-primary">254</p>
                  <div className="flex items-center text-green-600 text-sm">
                    <Icon name="TrendingUp" size={16} className="mr-1" />
                    <span>+5%</span>
                  </div>
                </div>
              </div>

              <div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow">
                <div className="flex items-center mb-4">
                  <div className="bg-green-100 p-3 rounded-full mr-4">
                    <Icon name="Activity" size={24} className="text-green-600" />
                  </div>
                  <h3 className="font-semibold text-coop-dark">Transacciones Hoy</h3>
                </div>
                <div className="flex items-end justify-between">
                  <p className="text-2xl font-bold text-coop-primary">127</p>
                  <div className="flex items-center text-green-600 text-sm">
                    <Icon name="TrendingUp" size={16} className="mr-1" />
                    <span>+12%</span>
                  </div>
                </div>
              </div>

              <div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow">
                <div className="flex items-center mb-4">
                  <div className="bg-purple-100 p-3 rounded-full mr-4">
                    <Icon name="DollarSign" size={24} className="text-purple-600" />
                  </div>
                  <h3 className="font-semibold text-coop-dark">Balance Total</h3>
                </div>
                <div className="flex items-end justify-between">
                  <p className="text-2xl font-bold text-coop-primary">$1.2M</p>
                  <div className="flex items-center text-green-600 text-sm">
                    <Icon name="TrendingUp" size={16} className="mr-1" />
                    <span>+8%</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Ventanas flotantes */}
        {currentPageWindows.map((window) => (
          <WindowPanel
            key={window.id}
            window={window}
            isActive={activeWindowId === window.id}
            setActiveTab={setActiveWindow}
            closeWindow={closeWindow}
            updateWindowPosition={updateWindowPosition}
            minimizeWindow={minimizeWindow}
            toggleMaximize={toggleMaximize}
          />
        ))}

        {/* Mensaje de error */}
        {errorMessage && (
          <div className="fixed top-4 right-4 bg-red-50 border-l-4 border-red-500 p-4 rounded shadow-lg z-50">
            <div className="flex items-center">
              <Icon name="AlertTriangle" size={20} className="text-red-500 mr-3" />
              <div>
                <p className="font-bold text-red-800">Límite alcanzado</p>
                <p className="text-sm text-red-600">{errorMessage}</p>
              </div>
            </div>
          </div>
        )}

        {/* Controles de paginación */}
        {totalPages > 1 && (
          <div className="fixed bottom-4 right-4 flex bg-white rounded-lg shadow-lg border border-gray-200 z-20">
            <button
              className={`p-3 rounded-l-lg transition-colors ${
                currentPage > 0
                  ? "bg-blue-50 text-blue-600 hover:bg-blue-100"
                  : "text-gray-400 cursor-not-allowed"
              }`}
              onClick={prevPage}
              disabled={currentPage === 0}
              title="Página anterior"
            >
              <ChevronLeft size={20} />
            </button>
            <div className="flex items-center justify-center px-4 text-sm text-gray-700 border-x border-gray-200">
              {currentPage + 1} / {totalPages}
            </div>
            <button
              className={`p-3 rounded-r-lg transition-colors ${
                currentPage < totalPages - 1
                  ? "bg-blue-50 text-blue-600 hover:bg-blue-100"
                  : "text-gray-400 cursor-not-allowed"
              }`}
              onClick={nextPage}
              disabled={currentPage === totalPages - 1}
              title="Página siguiente"
            >
              <ChevronRight size={20} />
            </button>
          </div>
        )}

        {/* Barra de tareas para ventanas minimizadas */}
        {minimizedWindows.length > 0 && (
          <div className="fixed bottom-0 left-64 right-0 bg-gray-200 p-2 border-t border-gray-300 flex flex-wrap gap-2 z-10">
            {windows
              .filter((w) => minimizedWindows.includes(w.id))
              .map((window) => (
                <button
                  key={window.id}
                  className="flex items-center gap-2 px-3 py-2 bg-white border border-gray-300 rounded text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                  onClick={() => handleRestoreWindow(window.id)}
                >
                  <Icon name="Window" size={16} />
                  <span className="truncate max-w-32">{window.title}</span>
                </button>
              ))}
          </div>
        )}
      </div>
    </div>
  );
};

export default Dashboard;