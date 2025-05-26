import React, { useState } from 'react';
import Icon from '../UI/Icon'; // Asegurar que esta ruta es correcta

const SettingsWindow = ({ subModuleId }) => {
  const [activeTab, setActiveTab] = useState('user');
  const [passwordData, setPasswordData] = useState({
    current: '',
    new: '',
    confirm: ''
  });
  const [userData, setUserData] = useState({
    name: 'Usuario Administrador',
    email: 'admin@sistema.com',
    language: 'es',
    timezone: 'America/Guayaquil',
    theme: 'light',
    notifications: true
  });
  const [systemSettings, setSystemSettings] = useState({
    companyName: 'COAC Sistema Financiero',
    logo: null,
    currency: 'USD',
    dateFormat: 'DD/MM/YYYY',
    sessionTimeout: 30,
    defaultLanguage: 'es'
  });

  // Función para manejar cambios en form de usuario
  const handleUserChange = (field, value) => {
    setUserData(prev => ({
      ...prev,
      [field]: value
    }));
  };

  // Función para manejar cambios en form de sistema
  const handleSystemChange = (field, value) => {
    setSystemSettings(prev => ({
      ...prev,
      [field]: value
    }));
  };

  // Función para cambiar contraseña
  const handlePasswordChange = (field, value) => {
    setPasswordData(prev => ({
      ...prev,
      [field]: value
    }));
  };

  // Función para enviar formulario de cambio de contraseña
  const handlePasswordSubmit = (e) => {
    e.preventDefault();
    if (passwordData.new !== passwordData.confirm) {
      alert('Las contraseñas no coinciden');
      return;
    }
    
    if (passwordData.new.length < 8) {
      alert('La contraseña debe tener al menos 8 caracteres');
      return;
    }
    
    alert('Contraseña actualizada correctamente');
    setPasswordData({ current: '', new: '', confirm: '' });
  };

  // Estilos inline para garantizar consistencia
  const styles = {
    container: {
      padding: '1.5rem',
      backgroundColor: '#f0f9ff', // coop-light
      height: '100%',
      overflow: 'auto'
    },
    header: {
      fontSize: '1.25rem',
      fontWeight: 'bold',
      marginBottom: '1.5rem',
      color: '#0c4a6e', // coop-dark
      paddingBottom: '0.5rem',
      borderBottom: '1px solid #e5e7eb' // gray-200
    },
    tabsContainer: {
      display: 'flex',
      borderBottom: '1px solid #e5e7eb',
      marginBottom: '1.5rem'
    },
    tab: {
      padding: '0.75rem 1.25rem',
      fontSize: '0.875rem',
      fontWeight: '500',
      color: '#6b7280', // gray-500
      cursor: 'pointer',
      borderBottom: '2px solid transparent',
      transition: 'all 150ms'
    },
    activeTab: {
      color: '#0ea5e9', // coop-primary
      borderBottom: '2px solid #0ea5e9'
    },
    tabIcon: {
      marginRight: '0.5rem'
    },
    contentContainer: {
      backgroundColor: 'white',
      borderRadius: '0.5rem',
      boxShadow: '0 1px 3px rgba(0, 0, 0, 0.1)',
      padding: '1.5rem',
      marginBottom: '1.5rem'
    },
    sectionTitle: {
      fontSize: '1rem',
      fontWeight: '600',
      color: '#0c4a6e', // coop-dark
      marginBottom: '1rem'
    },
    formGrid: {
      display: 'grid',
      gridTemplateColumns: 'repeat(auto-fit, minmax(250px, 1fr))',
      gap: '1.5rem'
    },
    formGroup: {
      marginBottom: '1.25rem'
    },
    label: {
      display: 'block',
      fontSize: '0.875rem',
      fontWeight: '500',
      color: '#475569', // coop-neutral
      marginBottom: '0.5rem'
    },
    input: {
      width: '100%',
      padding: '0.625rem 0.75rem',
      borderRadius: '0.375rem',
      border: '1px solid #e5e7eb',
      backgroundColor: 'white',
      fontSize: '0.875rem',
      outline: 'none',
      transition: 'border-color 150ms'
    },
    inputFocus: {
      borderColor: '#0ea5e9' // coop-primary
    },
    select: {
      width: '100%',
      padding: '0.625rem 0.75rem',
      borderRadius: '0.375rem',
      border: '1px solid #e5e7eb',
      backgroundColor: 'white',
      fontSize: '0.875rem',
      outline: 'none'
    },
    checkboxContainer: {
      display: 'flex',
      alignItems: 'center'
    },
    checkbox: {
      marginRight: '0.5rem',
      width: '1rem',
      height: '1rem'
    },
    button: {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '0.625rem 1.25rem',
      backgroundColor: '#0ea5e9', // coop-primary
      color: 'white',
      borderRadius: '0.375rem',
      fontWeight: '500',
      fontSize: '0.875rem',
      border: 'none',
      cursor: 'pointer',
      transition: 'background-color 150ms'
    },
    secondaryButton: {
      backgroundColor: 'white',
      color: '#0ea5e9',
      border: '1px solid #0ea5e9'
    },
    buttonIcon: {
      marginRight: '0.5rem'
    },
    buttonsContainer: {
      display: 'flex',
      justifyContent: 'flex-end',
      gap: '0.75rem',
      marginTop: '1rem'
    }
  };

  // Renderizar el contenido según la pestaña activa
  const renderContent = () => {
    switch (activeTab) {
      case 'user':
        return (
          <div>
            <h3 style={styles.sectionTitle}>Información de Usuario</h3>
            <div style={styles.formGrid}>
              <div style={styles.formGroup}>
                <label style={styles.label}>Nombre</label>
                <input
                  type="text"
                  value={userData.name}
                  onChange={(e) => handleUserChange('name', e.target.value)}
                  style={styles.input}
                />
              </div>
              <div style={styles.formGroup}>
                <label style={styles.label}>Correo Electrónico</label>
                <input
                  type="email"
                  value={userData.email}
                  onChange={(e) => handleUserChange('email', e.target.value)}
                  style={styles.input}
                />
              </div>
            </div>
            
            <div style={styles.formGrid}>
              <div style={styles.formGroup}>
                <label style={styles.label}>Idioma</label>
                <select
                  value={userData.language}
                  onChange={(e) => handleUserChange('language', e.target.value)}
                  style={styles.select}
                >
                  <option value="es">Español</option>
                  <option value="en">English</option>
                </select>
              </div>
              <div style={styles.formGroup}>
                <label style={styles.label}>Zona Horaria</label>
                <select
                  value={userData.timezone}
                  onChange={(e) => handleUserChange('timezone', e.target.value)}
                  style={styles.select}
                >
                  <option value="America/Guayaquil">América/Guayaquil (UTC-5)</option>
                  <option value="America/Bogota">América/Bogotá (UTC-5)</option>
                  <option value="America/Lima">América/Lima (UTC-5)</option>
                  <option value="America/Mexico_City">América/Ciudad de México (UTC-6)</option>
                </select>
              </div>
            </div>
            
            <div style={styles.formGrid}>
              <div style={styles.formGroup}>
                <label style={styles.label}>Tema</label>
                <select
                  value={userData.theme}
                  onChange={(e) => handleUserChange('theme', e.target.value)}
                  style={styles.select}
                >
                  <option value="light">Claro</option>
                  <option value="dark">Oscuro</option>
                  <option value="system">Sistema</option>
                </select>
              </div>
              <div style={styles.formGroup}>
                <div style={styles.checkboxContainer}>
                  <input
                    type="checkbox"
                    checked={userData.notifications}
                    onChange={(e) => handleUserChange('notifications', e.target.checked)}
                    style={styles.checkbox}
                    id="notifications"
                  />
                  <label htmlFor="notifications" style={{ fontSize: '0.875rem', color: '#475569' }}>
                    Recibir notificaciones
                  </label>
                </div>
              </div>
            </div>
            
            <div style={styles.buttonsContainer}>
              <button style={{...styles.button, ...styles.secondaryButton}}>
                Cancelar
              </button>
              <button style={styles.button}>
                <span style={styles.buttonIcon}>
                  <Icon name="Save" size={16} />
                </span>
                Guardar Cambios
              </button>
            </div>
          </div>
        );
        
      case 'security':
        return (
          <div>
            <h3 style={styles.sectionTitle}>Cambiar Contraseña</h3>
            <form onSubmit={handlePasswordSubmit}>
              <div style={styles.formGroup}>
                <label style={styles.label}>Contraseña Actual</label>
                <input
                  type="password"
                  value={passwordData.current}
                  onChange={(e) => handlePasswordChange('current', e.target.value)}
                  style={styles.input}
                  required
                />
              </div>
              <div style={styles.formGroup}>
                <label style={styles.label}>Nueva Contraseña</label>
                <input
                  type="password"
                  value={passwordData.new}
                  onChange={(e) => handlePasswordChange('new', e.target.value)}
                  style={styles.input}
                  required
                  minLength={8}
                />
                <p style={{ fontSize: '0.75rem', color: '#6b7280', marginTop: '0.25rem' }}>
                  La contraseña debe tener al menos 8 caracteres
                </p>
              </div>
              <div style={styles.formGroup}>
                <label style={styles.label}>Confirmar Contraseña</label>
                <input
                  type="password"
                  value={passwordData.confirm}
                  onChange={(e) => handlePasswordChange('confirm', e.target.value)}
                  style={styles.input}
                  required
                />
              </div>
              
              <div style={styles.buttonsContainer}>
                <button type="reset" style={{...styles.button, ...styles.secondaryButton}}>
                  Cancelar
                </button>
                <button type="submit" style={styles.button}>
                  <span style={styles.buttonIcon}>
                    <Icon name="Lock" size={16} />
                  </span>
                  Actualizar Contraseña
                </button>
              </div>
            </form>
            
            <div style={{ marginTop: '2rem' }}>
              <h3 style={styles.sectionTitle}>Seguridad de la Cuenta</h3>
              <div style={styles.formGroup}>
                <label style={styles.label}>Autenticación de Dos Factores</label>
                <button style={{...styles.button, marginTop: '0.5rem'}}>
                  <span style={styles.buttonIcon}>
                    <Icon name="Shield" size={16} />
                  </span>
                  Configurar 2FA
                </button>
              </div>
            </div>
          </div>
        );
        
      case 'system':
        return (
          <div>
            <h3 style={styles.sectionTitle}>Configuración del Sistema</h3>
            <div style={styles.formGrid}>
              <div style={styles.formGroup}>
                <label style={styles.label}>Nombre de la Empresa</label>
                <input
                  type="text"
                  value={systemSettings.companyName}
                  onChange={(e) => handleSystemChange('companyName', e.target.value)}
                  style={styles.input}
                />
              </div>
              <div style={styles.formGroup}>
                <label style={styles.label}>Moneda</label>
                <select
                  value={systemSettings.currency}
                  onChange={(e) => handleSystemChange('currency', e.target.value)}
                  style={styles.select}
                >
                  <option value="USD">Dólar Estadounidense (USD)</option>
                  <option value="EUR">Euro (EUR)</option>
                  <option value="COP">Peso Colombiano (COP)</option>
                  <option value="MXN">Peso Mexicano (MXN)</option>
                </select>
              </div>
            </div>
            
            <div style={styles.formGrid}>
              <div style={styles.formGroup}>
                <label style={styles.label}>Formato de Fecha</label>
                <select
                  value={systemSettings.dateFormat}
                  onChange={(e) => handleSystemChange('dateFormat', e.target.value)}
                  style={styles.select}
                >
                  <option value="DD/MM/YYYY">DD/MM/YYYY</option>
                  <option value="MM/DD/YYYY">MM/DD/YYYY</option>
                  <option value="YYYY-MM-DD">YYYY-MM-DD</option>
                </select>
              </div>
              <div style={styles.formGroup}>
                <label style={styles.label}>Tiempo de Inactividad (minutos)</label>
                <input
                  type="number"
                  min="5"
                  max="120"
                  value={systemSettings.sessionTimeout}
                  onChange={(e) => handleSystemChange('sessionTimeout', parseInt(e.target.value))}
                  style={styles.input}
                />
              </div>
            </div>
            
            <div style={styles.formGrid}>
              <div style={styles.formGroup}>
                <label style={styles.label}>Idioma Predeterminado</label>
                <select
                  value={systemSettings.defaultLanguage}
                  onChange={(e) => handleSystemChange('defaultLanguage', e.target.value)}
                  style={styles.select}
                >
                  <option value="es">Español</option>
                  <option value="en">English</option>
                </select>
              </div>
              <div style={styles.formGroup}>
                <label style={styles.label}>Logo de la Empresa</label>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                  <button style={{...styles.button, ...styles.secondaryButton}}>
                    <span style={styles.buttonIcon}>
                      <Icon name="Upload" size={16} />
                    </span>
                    Subir Logo
                  </button>
                  {systemSettings.logo && (
                    <button style={{
                      padding: '0.5rem',
                      borderRadius: '0.375rem',
                      backgroundColor: 'transparent',
                      color: '#ef4444', // coop-danger
                      border: 'none',
                      cursor: 'pointer'
                    }}>
                      <Icon name="Trash" size={16} />
                    </button>
                  )}
                </div>
              </div>
            </div>
            
            <div style={styles.buttonsContainer}>
              <button style={{...styles.button, ...styles.secondaryButton}}>
                Cancelar
              </button>
              <button style={styles.button}>
                <span style={styles.buttonIcon}>
                  <Icon name="Save" size={16} />
                </span>
                Guardar Configuración
              </button>
            </div>
          </div>
        );
        
      default:
        return null;
    }
  };

  return (
    <div style={styles.container}>
      <h2 style={styles.header}>Configuración</h2>
      
      <div style={styles.tabsContainer}>
        <div 
          style={{
            ...styles.tab,
            ...(activeTab === 'user' ? styles.activeTab : {})
          }}
          onClick={() => setActiveTab('user')}
        >
          <Icon name="User" size={16} style={styles.tabIcon} />
          Preferencias de Usuario
        </div>
        <div 
          style={{
            ...styles.tab,
            ...(activeTab === 'security' ? styles.activeTab : {})
          }}
          onClick={() => setActiveTab('security')}
        >
          <Icon name="Lock" size={16} style={styles.tabIcon} />
          Seguridad
        </div>
        <div 
          style={{
            ...styles.tab,
            ...(activeTab === 'system' ? styles.activeTab : {})
          }}
          onClick={() => setActiveTab('system')}
        >
          <Icon name="Settings" size={16} style={styles.tabIcon} />
          Sistema
        </div>
      </div>
      
      <div style={styles.contentContainer}>
        {renderContent()}
      </div>
    </div>
  );
};

export default SettingsWindow;