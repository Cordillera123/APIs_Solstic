// Archivo: useWindows.js
import { useState, useCallback, useEffect } from 'react';

const useWindows = () => {
  // Estado para almacenar todas las ventanas abiertas
  const [windows, setWindows] = useState([]);
  // Estado para la ventana activa
  const [activeWindowId, setActiveWindowId] = useState(null);
  // Estado para mensajes de error
  const [errorMessage, setErrorMessage] = useState(null);
  // Estado para ventanas minimizadas
  const [minimizedWindows, setMinimizedWindows] = useState([]);
  // Estado para z-index base
  const [baseZIndex, setBaseZIndex] = useState(10);

  // Constantes para configuración de ventanas
  const MAX_WINDOWS = 10;
  const DEFAULT_WINDOW_WIDTH = 800;
  const DEFAULT_WINDOW_HEIGHT = 600;
  const MIN_WINDOW_WIDTH = 400;
  const MIN_WINDOW_HEIGHT = 300;

  // Posiciones iniciales escalonadas para ventanas nuevas
  const getInitialPosition = useCallback((index) => {
    const offsetX = 50;
    const offsetY = 40;
    const maxOffset = 200;
    
    // Calcular posición basada en el índice, con un efecto cascada
    const x = (offsetX * (index % 5)) % maxOffset;
    const y = (offsetY * (index % 5)) % maxOffset;
    
    return { x, y };
  }, []);

  // Abrir una nueva ventana
 const openWindow = useCallback((moduleId, subModuleId = null, moduleName = '', icon = '', subModuleName = '', component = null) => {
  console.log('useWindows.openWindow called with:', { moduleId, subModuleId, moduleName, icon, subModuleName, component });
  
    // Verificar si la ventana ya está abierta
    const windowId = subModuleId ? `${moduleId}-${subModuleId}` : moduleId;
    const existingWindow = windows.find(w => w.id === windowId);

    // Verificar si está minimizada
    const isMinimized = minimizedWindows.includes(windowId);

    if (existingWindow) {
      // Si está minimizada, la restauramos
      if (isMinimized) {
        setMinimizedWindows(prev => prev.filter(id => id !== windowId));
      }
      
      // Activamos la ventana y la traemos al frente
      setActiveWindowId(windowId);
      // Actualizar zIndex para traerla al frente
      setWindows(prev => prev.map(w => 
        w.id === windowId 
          ? { ...w, zIndex: baseZIndex + 1 } 
          : w
      ));
      setBaseZIndex(prev => prev + 1);
      
      return { success: true };
    }

    // Verificar si ya alcanzamos el límite de ventanas
    if (windows.length >= MAX_WINDOWS) {
      setErrorMessage("Has alcanzado el límite máximo de ventanas abiertas. Cierra alguna para continuar.");
      setTimeout(() => setErrorMessage(null), 5000); // Limpiar el mensaje después de 5 segundos
      return { success: false, message: "Límite de ventanas alcanzado" };
    }

    // Obtener posición inicial para la nueva ventana
    const position = getInitialPosition(windows.length);
    
    // Crear nueva ventana
    const newWindow = {
      id: windowId,
      moduleId: moduleId,
      subModuleId: subModuleId,
      component:component,
      module: {
        name: moduleName,
        icon: icon
      },
      subModuleName: subModuleName, // Guardar el nombre del submódulo directamente
      position: {
        x: position.x,
        y: position.y
      },
      size: {
        width: DEFAULT_WINDOW_WIDTH,
        height: DEFAULT_WINDOW_HEIGHT
      },
      isMinimized: false,
      isMaximized: false,
      zIndex: baseZIndex + 1, // Asignar un zIndex para que aparezca encima
      createdAt: new Date().getTime() // Timestamp para ordenar
    };
    console.log('Created newWindow:', newWindow);

    // Incrementar el zIndex base
    setBaseZIndex(prev => prev + 1);
    
    // Agregar la nueva ventana al estado
    setWindows(prev => [...prev, newWindow]);
    // Establecerla como activa
    setActiveWindowId(windowId);
    return { success: true };
  }, [windows, minimizedWindows, baseZIndex, getInitialPosition]);

  // Cerrar una ventana
  const closeWindow = useCallback((windowId) => {
    // Eliminar de las minimizadas si está allí
    if (minimizedWindows.includes(windowId)) {
      setMinimizedWindows(prev => prev.filter(id => id !== windowId));
    }
    
    // Remover la ventana
    setWindows(prev => prev.filter(window => window.id !== windowId));
    
    // Si cerramos la ventana activa, activamos la última ventana
    if (activeWindowId === windowId) {
      const remainingWindows = windows.filter(window => window.id !== windowId);
      if (remainingWindows.length > 0) {
        // Ordenar por zIndex para activar la ventana que estaba más arriba
        const sortedWindows = [...remainingWindows].sort((a, b) => b.zIndex - a.zIndex);
        setActiveWindowId(sortedWindows[0].id);
      } else {
        setActiveWindowId(null);
      }
    }
  }, [windows, activeWindowId, minimizedWindows]);

  // Establecer ventana activa
  const setActiveWindow = useCallback((windowId) => {
    // Si la ventana está minimizada, la restauramos
    if (minimizedWindows.includes(windowId)) {
      setMinimizedWindows(prev => prev.filter(id => id !== windowId));
    }
    
    // Activar la ventana y actualizar su zIndex
    setActiveWindowId(windowId);
    setWindows(prev => prev.map(window => 
      window.id === windowId 
        ? { ...window, zIndex: baseZIndex + 1 } 
        : window
    ));
    setBaseZIndex(prev => prev + 1);
  }, [minimizedWindows, baseZIndex]);

  // Actualizar posición de ventana
  const updateWindowPosition = useCallback((windowId, x, y) => {
    setWindows(prev => 
      prev.map(window => 
        window.id === windowId 
          ? { ...window, position: { x, y }, isMaximized: false } 
          : window
      )
    );
  }, []);

  // Actualizar tamaño de ventana
  const updateWindowSize = useCallback((windowId, width, height) => {
    setWindows(prev => 
      prev.map(window => 
        window.id === windowId 
          ? { 
              ...window, 
              size: { 
                width: Math.max(width, MIN_WINDOW_WIDTH),
                height: Math.max(height, MIN_WINDOW_HEIGHT) 
              },
              isMaximized: false // Si se redimensiona, ya no está maximizada
            } 
          : window
      )
    );
  }, []);

  // Minimizar ventana
  const minimizeWindow = useCallback((windowId) => {
    // Agregar a la lista de minimizadas si no está ya
    if (!minimizedWindows.includes(windowId)) {
      setMinimizedWindows(prev => [...prev, windowId]);
    }
    
    // Si es la ventana activa, desactivarla
    if (activeWindowId === windowId) {
      // Encontrar la próxima ventana para activar
      const visibleWindows = windows.filter(w => 
        !minimizedWindows.includes(w.id) && w.id !== windowId
      );
      
      if (visibleWindows.length > 0) {
        // Ordenar por zIndex y activar la más alta
        const sortedWindows = [...visibleWindows].sort((a, b) => b.zIndex - a.zIndex);
        setActiveWindowId(sortedWindows[0].id);
      } else {
        setActiveWindowId(null);
      }
    }
  }, [windows, activeWindowId, minimizedWindows]);

  // Restaurar ventana minimizada
  const restoreWindow = useCallback((windowId) => {
    setMinimizedWindows(prev => prev.filter(id => id !== windowId));
    setActiveWindow(windowId); // Activarla y traerla al frente
  }, [setActiveWindow]);

  // Toggle maximizar/restaurar ventana
  const toggleMaximize = useCallback((windowId) => {
    setWindows(prev => 
      prev.map(window => 
        window.id === windowId 
          ? { ...window, isMaximized: !window.isMaximized } 
          : window
      )
    );
    // También la activamos
    setActiveWindow(windowId);
  }, [setActiveWindow]);

  // Ordenar ventanas por cascada
  const cascadeWindows = useCallback(() => {
    const visibleWindows = windows.filter(w => !minimizedWindows.includes(w.id));
    
    if (visibleWindows.length === 0) return;
    
    const updatedWindows = [...windows];
    
    // Ordenar por orden de creación
    const sortedWindows = [...visibleWindows].sort((a, b) => a.createdAt - b.createdAt);
    
    // Reposicionar en cascada
    sortedWindows.forEach((window, index) => {
      const windowIndex = updatedWindows.findIndex(w => w.id === window.id);
      if (windowIndex !== -1) {
        updatedWindows[windowIndex] = {
          ...updatedWindows[windowIndex],
          position: { x: index * 40, y: index * 30 },
          isMaximized: false,
          zIndex: baseZIndex + index + 1
        };
      }
    });
    
    setWindows(updatedWindows);
    setBaseZIndex(prev => prev + visibleWindows.length);
    
    // Activar la última ventana
    if (sortedWindows.length > 0) {
      setActiveWindowId(sortedWindows[sortedWindows.length - 1].id);
    }
  }, [windows, minimizedWindows, baseZIndex]);

  // Ordenar ventanas en mosaico
  const tileWindows = useCallback(() => {
    const visibleWindows = windows.filter(w => !minimizedWindows.includes(w.id));
    
    if (visibleWindows.length === 0) return;
    
    const updatedWindows = [...windows];
    const numWindows = visibleWindows.length;
    
    // Calcular grid
    let cols = Math.ceil(Math.sqrt(numWindows));
    let rows = Math.ceil(numWindows / cols);
    
    // Si hay solo 2 ventanas, las colocamos lado a lado
    if (numWindows === 2) {
      cols = 2;
      rows = 1;
    }
    
    // Calcular tamaño de cada ventana
    const windowWidth = Math.floor(window.innerWidth / cols);
    const windowHeight = Math.floor(window.innerHeight / rows);
    
    // Reposicionar en grid
    visibleWindows.forEach((window, index) => {
      const row = Math.floor(index / cols);
      const col = index % cols;
      
      const windowIndex = updatedWindows.findIndex(w => w.id === window.id);
      if (windowIndex !== -1) {
        updatedWindows[windowIndex] = {
          ...updatedWindows[windowIndex],
          position: { x: col * windowWidth, y: row * windowHeight },
          size: { width: windowWidth, height: windowHeight },
          isMaximized: false,
          zIndex: baseZIndex + index + 1
        };
      }
    });
    
    setWindows(updatedWindows);
    setBaseZIndex(prev => prev + visibleWindows.length);
  }, [windows, minimizedWindows, baseZIndex]);

  // Cerrar todas las ventanas
  const closeAllWindows = useCallback(() => {
    setWindows([]);
    setMinimizedWindows([]);
    setActiveWindowId(null);
  }, []);

  // Efecto para manejar atajos de teclado
  useEffect(() => {
    const handleKeyDown = (e) => {
      // Ctrl+F4 o Alt+F4 para cerrar la ventana activa
      if ((e.ctrlKey || e.altKey) && e.key === 'F4' && activeWindowId) {
        e.preventDefault();
        closeWindow(activeWindowId);
      }
      
      // Ctrl+Tab para cambiar entre ventanas
      if (e.ctrlKey && e.key === 'Tab') {
        e.preventDefault();
        
        const visibleWindows = windows.filter(w => !minimizedWindows.includes(w.id));
        if (visibleWindows.length > 1) {
          const currentIndex = visibleWindows.findIndex(w => w.id === activeWindowId);
          const nextIndex = (currentIndex + 1) % visibleWindows.length;
          setActiveWindow(visibleWindows[nextIndex].id);
        }
      }
    };
    
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [windows, activeWindowId, minimizedWindows, closeWindow, setActiveWindow]);

  return {
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
    tileWindows,
    closeAllWindows,
    MAX_WINDOWS
  };
};

export default useWindows;