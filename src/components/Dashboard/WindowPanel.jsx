// Archivo: WindowPanel.jsx
import React, { useState, useEffect, useRef, useCallback } from 'react';
import { X, Minimize2, Maximize2, ChevronDown, RefreshCw, Move } from 'lucide-react';
import Icon from '../UI/Icon';
import AccountsWindow from '../Windows/AccountsWindow';
import ReportsWindow from '../Windows/ReportsWindow';
import SettingsWindow from '../Windows/SettingsWindow';
import TransactionsWindow from '../Windows/TransactionsWindow';
import ParameWindows from '../Windows/ParameWindows';
import AsgiPerWindows from '../Windows/AsgiPerWindows';

const WindowPanel = ({ 
  window, 
  isActive, 
  setActiveTab, 
  closeWindow, 
  updateWindowPosition,
  minimizeWindow,
  toggleMaximize
}) => {
  const [isMaximized, setIsMaximized] = useState(window.isMaximized || false);
  const [isLoading, setIsLoading] = useState(false);
  const [position, setPosition] = useState({ x: window.position.x, y: window.position.y });
  const [isDragging, setIsDragging] = useState(false);
  const [dragOffset, setDragOffset] = useState({ x: 0, y: 0 });
  const [isMoveMode, setIsMoveMode] = useState(false);
  const [isResizing, setIsResizing] = useState(false);
  const [resizeDirection, setResizeDirection] = useState('');
  const [originalSize, setOriginalSize] = useState({ width: window.size.width, height: window.size.height });
  
  const windowRef = useRef(null);
  
  // Actualizar estado local cuando cambian propiedades externas
  useEffect(() => {
    if (window.isMaximized !== undefined && window.isMaximized !== isMaximized) {
      setIsMaximized(window.isMaximized);
    }
    
    // Actualizar posición cuando cambia desde el exterior (importante para mosaico)
    if (window.position && (window.position.x !== position.x || window.position.y !== position.y)) {
      setPosition({ 
        x: window.position.x, 
        y: window.position.y 
      });
    }
    
    // Actualizar tamaño cuando cambia desde el exterior (importante para mosaico)
    if (window.size && (window.size.width !== originalSize.width || window.size.height !== originalSize.height)) {
      setOriginalSize({
        width: window.size.width,
        height: window.size.height
      });
    }
  }, [window.isMaximized, window.position, window.size, isMaximized, position, originalSize]);
  
  // Función para refrescar el contenido
  const refreshContent = () => {
    setIsLoading(true);
    setTimeout(() => {
      setIsLoading(false);
    }, 800);
  };
  
  // Manejadores de arrastre para mover ventanas

// Manejador de inicio de movimiento
const handleMouseDown = (e) => {
  if (isMaximized) return;

  setActiveTab(window.id);

  const rect = windowRef.current.getBoundingClientRect();
  setDragOffset({
    x: e.clientX - rect.left,
    y: e.clientY - rect.top
  });
  setIsDragging(true);
};
// Movimiento del mouse con `useCallback`
const handleMouseMove = useCallback((e) => {
  if (!isDragging && !isResizing) return;

  const innerWidth = window.innerWidth;
  const innerHeight = window.innerHeight;

  if (isDragging) {
    const newX = e.clientX - dragOffset.x;
    const newY = e.clientY - dragOffset.y;

    const maxWidth = innerWidth - (windowRef.current?.offsetWidth || 800);
    const maxHeight = innerHeight - (windowRef.current?.offsetHeight || 600);

    const limitedX = Math.max(0, Math.min(newX, maxWidth));
    const limitedY = Math.max(0, Math.min(newY, maxHeight));

    setPosition({ x: limitedX, y: limitedY });
  } else if (isResizing) {
    const rect = windowRef.current.getBoundingClientRect();
    let newWidth = originalSize.width;
    let newHeight = originalSize.height;

    if (resizeDirection.includes('e')) {
      newWidth = Math.max(400, e.clientX - rect.left);
    }
    if (resizeDirection.includes('s')) {
      newHeight = Math.max(300, e.clientY - rect.top);
    }
    if (resizeDirection.includes('w')) {
      const diff = rect.left - e.clientX;
      newWidth = Math.max(400, originalSize.width + diff);
      if (newWidth !== originalSize.width) {
        setPosition({
          x: position.x - (newWidth - originalSize.width),
          y: position.y
        });
      }
    }
    if (resizeDirection.includes('n')) {
      const diff = rect.top - e.clientY;
      newHeight = Math.max(300, originalSize.height + diff);
      if (newHeight !== originalSize.height) {
        setPosition({
          x: position.x,
          y: position.y - (newHeight - originalSize.height)
        });
      }
    }

    setOriginalSize({
      width: newWidth,
      height: newHeight
    });
  }
}, [isDragging, isResizing, dragOffset, resizeDirection, originalSize, position]);



// Finaliza movimiento o redimensionamiento
const handleMouseUp = useCallback(() => {
  if (isDragging) {
    updateWindowPosition(window.id, position.x, position.y);
  }

  setIsDragging(false);
  setIsResizing(false);
  setIsMoveMode(false);
}, [isDragging, position, updateWindowPosition, window.id]);

// Listener de eventos globales
useEffect(() => {
  if (isDragging || isResizing || isMoveMode) {
    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);
  }

  return () => {
    document.removeEventListener('mousemove', handleMouseMove);
    document.removeEventListener('mouseup', handleMouseUp);
  };
}, [isDragging, isMoveMode, isResizing, handleMouseMove, handleMouseUp]);

  // Activar modo de movimiento (botón específico)
  const activateMoveMode = (e) => {
    e.stopPropagation();
    setIsMoveMode(true);
    handleMouseDown(e);
  };
  
  // Iniciar redimensionamiento
  const startResize = (e, direction) => {
    e.stopPropagation();
    if (isMaximized) return;
    
    setResizeDirection(direction);
    setIsResizing(true);
    setOriginalSize({
      width: windowRef.current.offsetWidth,
      height: windowRef.current.offsetHeight
    });
  };
  
  // Manejar maximización con la función mejorada
  const handleMaximize = (e) => {
    e.stopPropagation();
    if (toggleMaximize) {
      toggleMaximize(window.id);
    } else {
      setIsMaximized(!isMaximized);
    }
  };
  
  // Manejar minimización
  const handleMinimize = (e) => {
    e.stopPropagation();
    if (minimizeWindow) {
      minimizeWindow(window.id);
    }
  };
  
  // Función para obtener el contenido de la ventana según el módulo
  // En WindowPanel.jsx, busca la función getWindowContent y reemplázala por:

const getWindowContent = () => {
  
  
  const componentName = window.component;

  
  // ✅ Usar los componentes importados directamente
  switch (componentName) {
    case 'ParameWindows':
      return <ParameWindows data={window.data} />;
    
    case 'AsgiPerWindows':
      return <AsgiPerWindows data={window.data} />;
    
    case 'AccountsWindow':
      return <AccountsWindow data={window.data} />;
    
    case 'ReportsWindow':
      return <ReportsWindow data={window.data} />;
    
    case 'SettingsWindow':
      return <SettingsWindow data={window.data} />;
    
    case 'TransactionsWindow':
      return <TransactionsWindow data={window.data} />;
    
    default:
      // Contenido por defecto
      return (
        <div className="p-6" style={{ padding: '1.5rem', backgroundColor: '#f0f9ff', height: '100%' }}>
          <h2 className="text-xl font-bold mb-4 text-gray-800">
            {window.module.name}
          </h2>
          <div className="bg-yellow-50 border border-yellow-200 rounded-md p-4">
            <p className="text-yellow-800 text-sm">
              Componente: <strong>{componentName || 'No definido'}</strong>
            </p>
            <p className="text-yellow-700 text-sm mt-1">
              Este componente está en desarrollo.
            </p>
          </div>
          
          <details className="bg-gray-50 p-4 rounded-md border mt-4">
            <summary className="cursor-pointer font-medium text-gray-700 mb-2">
              Ver datos de la ventana
            </summary>
            <pre className="text-xs bg-white p-3 rounded border overflow-auto max-h-60">
              {JSON.stringify(window, null, 2)}
            </pre>
          </details>
        </div>
      );
  }
};
  
  // Estilos dinámicos según estado
  const windowStyles = {
    position: 'absolute',
    left: isMaximized ? '0' : `${position.x}px`,
    top: isMaximized ? '0' : `${position.y}px`,
    width: isMaximized ? '100%' : `${originalSize.width}px`,
    height: isMaximized ? 'calc(100% - 36px)' : `${originalSize.height}px`,
    zIndex: isActive ? 10 : 5,
    transition: isDragging || isResizing ? 'none' : 'all 0.2s ease-in-out', // Desactivar transición durante arrastre
    cursor: isMoveMode ? 'move' : 'default',
    backgroundColor: 'white',
    borderRadius: '0.5rem',
    boxShadow: isActive 
      ? '0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1)' 
      : '0 4px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)',
    overflow: 'hidden',
    border: isActive ? '1px solid #0ea5e9' : '1px solid rgba(0, 0, 0, 0.05)'
  };

  const headerStyle = {
    background: 'linear-gradient(to right, #0ea5e9, #0369a1)',
    color: 'white',
    padding: '0.5rem',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    cursor: isMoveMode ? 'move' : 'pointer',
    userSelect: 'none'
  };

  const iconContainerStyle = {
    marginRight: '0.5rem',
    backgroundColor: 'rgba(255, 255, 255, 0.2)',
    padding: '0.25rem',
    borderRadius: '0.25rem'
  };

  const titleStyle = {
    fontWeight: '500', 
    fontSize: '0.875rem', 
    color: 'white'
  };

  const subtitleStyle = {
    marginLeft: '0.5rem', 
    fontSize: '0.75rem', 
    color: 'rgba(255, 255, 255, 0.9)'
  };

  const buttonStyle = {
    backgroundColor: 'transparent',
    border: 'none',
    color: 'white',
    padding: '0.25rem',
    borderRadius: '0.25rem',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    cursor: 'pointer',
    transition: 'background-color 150ms'
  };

  const contentStyle = {
    overflow: 'auto',
    backgroundColor: '#f0f9ff', // coop-light
    height: 'calc(100% - 36px)'
  };

  const loadingStyle = {
    height: '100%',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    flexDirection: 'column',
    backgroundColor: '#f0f9ff'
  };

  // Agregar manejadores para los botones de redimensionamiento
  const resizeHandles = (
    <>
      <div 
        style={{ 
          position: 'absolute', 
          top: 0, 
          left: 0, 
          width: '10px', 
          height: '10px', 
          cursor: 'nw-resize',
          zIndex: 20
        }}
        onMouseDown={(e) => startResize(e, 'nw')}
      />
      <div 
        style={{ 
          position: 'absolute', 
          top: 0, 
          right: 0, 
          width: '10px', 
          height: '10px', 
          cursor: 'ne-resize',
          zIndex: 20
        }}
        onMouseDown={(e) => startResize(e, 'ne')}
      />
      <div 
        style={{ 
          position: 'absolute', 
          bottom: 0, 
          left: 0, 
          width: '10px', 
          height: '10px', 
          cursor: 'sw-resize',
          zIndex: 20
        }}
        onMouseDown={(e) => startResize(e, 'sw')}
      />
      <div 
        style={{ 
          position: 'absolute', 
          bottom: 0, 
          right: 0, 
          width: '10px', 
          height: '10px', 
          cursor: 'se-resize',
          zIndex: 20
        }}
        onMouseDown={(e) => startResize(e, 'se')}
      />
    </>
  );
  
  return (
    <div 
      ref={windowRef}
      style={windowStyles}
      onClick={() => setActiveTab(window.id)}
      className="window-panel"
    >
      {/* Manejadores de redimensionamiento */}
      {!isMaximized && resizeHandles}
      
      {/* Window header */}
      <div 
        style={headerStyle}
        onDoubleClick={handleMaximize}
        onMouseDown={isMoveMode ? undefined : handleMouseDown}
      >
        <div style={{ display: 'flex', alignItems: 'center' }}>
          <span style={iconContainerStyle}>
            <Icon name={window.module.icon} size={18} style={{ color: 'white' }} />
          </span>
          <span style={titleStyle}>{window.module.name}</span>
         {window.subModuleName && (
  <span style={subtitleStyle}>
    &gt; {window.subModuleName}
  </span>
)}
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
          <button 
            style={buttonStyle}
            onClick={(e) => {
              e.stopPropagation();
              refreshContent();
            }}
            title="Refrescar"
            onMouseEnter={(e) => {
              e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent';
            }}
          >
            <RefreshCw size={15} className={isLoading ? 'animate-spin' : ''} style={{ color: 'white' }} />
          </button>
          <button 
            style={buttonStyle}
            onClick={(e) => {
              e.stopPropagation();
              activateMoveMode(e);
            }}
            title="Mover ventana"
            onMouseEnter={(e) => {
              e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent';
            }}
          >
            <Move size={15} style={{ color: 'white' }} />
          </button>
          <button 
            style={buttonStyle}
            onClick={handleMinimize}
            title="Minimizar"
            onMouseEnter={(e) => {
              e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent';
            }}
          >
            <Minimize2 size={15} style={{ color: 'white' }} />
          </button>
          <button 
            style={buttonStyle}
            onClick={handleMaximize}
            title={isMaximized ? "Restaurar" : "Maximizar"}
            onMouseEnter={(e) => {
              e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent';
            }}
          >
            {isMaximized ? <ChevronDown size={15} style={{ color: 'white' }} /> : <Maximize2 size={15} style={{ color: 'white' }} />}
          </button>
          <button 
            style={{
              ...buttonStyle
            }}
            onClick={(e) => {
              e.stopPropagation();
              closeWindow(window.id);
            }}
            title="Cerrar"
            onMouseEnter={(e) => {
              e.currentTarget.style.backgroundColor = '#ef4444';
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent';
            }}
          >
            <X size={15} style={{ color: 'white' }} />
          </button>
        </div>
      </div>
      
      {/* Window content */}
      <div style={contentStyle}>
        {isLoading ? (
          <div style={loadingStyle}>
            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
              <RefreshCw size={32} style={{ color: '#0ea5e9', animation: 'spin 1s linear infinite', marginBottom: '0.75rem' }} />
              <p style={{ fontSize: '0.875rem', color: '#334155' }}>Cargando datos...</p>
            </div>
          </div>
        ) : (
          getWindowContent()
        )}
      </div>
    </div>
  );
};

export default WindowPanel;