import React, { useState } from 'react';
import Icon from '../UI/Icon'; // Asegurar que esta ruta es correcta

const ReportsWindow = ({ subModuleId }) => {
  const [selectedReportType, setSelectedReportType] = useState('');
  const [dateRange, setDateRange] = useState({ start: '', end: '' });
  const [filter, setFilter] = useState('');
  const [isGenerating, setIsGenerating] = useState(false);

  // Datos de muestra de tipos de reportes según la categoría
  const getReportTypes = () => {
    switch(subModuleId) {
      case 'financial':
        return [
          { id: 'balance-sheet', name: 'Balance General', icon: 'FileText' },
          { id: 'income-statement', name: 'Estado de Resultados', icon: 'FileText' },
          { id: 'cash-flow', name: 'Flujo de Efectivo', icon: 'FileText' },
          { id: 'financial-indicators', name: 'Indicadores Financieros', icon: 'BarChart' }
        ];
      case 'clients':
        return [
          { id: 'client-portfolio', name: 'Cartera de Clientes', icon: 'Users' },
          { id: 'client-activity', name: 'Actividad de Clientes', icon: 'Activity' },
          { id: 'client-demographics', name: 'Demografía de Clientes', icon: 'PieChart' },
          { id: 'new-clients', name: 'Nuevos Clientes', icon: 'UserPlus' }
        ];
      case 'operations':
        return [
          { id: 'transactions-summary', name: 'Resumen de Transacciones', icon: 'List' },
          { id: 'operational-performance', name: 'Rendimiento Operacional', icon: 'TrendingUp' },
          { id: 'branch-performance', name: 'Rendimiento por Sucursal', icon: 'Map' },
          { id: 'system-usage', name: 'Uso del Sistema', icon: 'Activity' }
        ];
      default:
        return [
          { id: 'balance-sheet', name: 'Balance General', icon: 'FileText' },
          { id: 'income-statement', name: 'Estado de Resultados', icon: 'FileText' },
          { id: 'client-portfolio', name: 'Cartera de Clientes', icon: 'Users' },
          { id: 'transactions-summary', name: 'Resumen de Transacciones', icon: 'List' },
          { id: 'operational-performance', name: 'Rendimiento Operacional', icon: 'TrendingUp' }
        ];
    }
  };

  // Datos de muestra de reportes recientes
  const recentReports = [
    { id: 1, name: 'Balance General Abril 2025', type: 'financial', date: '2025-04-30', format: 'PDF' },
    { id: 2, name: 'Estado de Resultados Q1 2025', type: 'financial', date: '2025-04-15', format: 'XLSX' },
    { id: 3, name: 'Cartera de Clientes', type: 'clients', date: '2025-04-10', format: 'PDF' },
    { id: 4, name: 'Rendimiento por Sucursal', type: 'operations', date: '2025-04-05', format: 'PDF' },
    { id: 5, name: 'Nuevos Clientes Marzo 2025', type: 'clients', date: '2025-04-01', format: 'XLSX' }
  ];

  // Filtrar reportes recientes
  const filteredReports = recentReports.filter(report => 
    (subModuleId ? report.type === subModuleId : true) && 
    report.name.toLowerCase().includes(filter.toLowerCase())
  );

  // Simular generación de reporte
  const handleGenerateReport = () => {
    if (!selectedReportType || !dateRange.start || !dateRange.end) {
      alert('Por favor, seleccione un tipo de reporte y rango de fechas');
      return;
    }
    
    setIsGenerating(true);
    setTimeout(() => {
      setIsGenerating(false);
      alert('Reporte generado con éxito');
    }, 2000);
  };

  // Función para formatear fecha
  const formatDate = (dateStr) => {
    const date = new Date(dateStr);
    return new Intl.DateTimeFormat('es-ES', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric'
    }).format(date);
  };

  // Determinar título basado en subModuleId
  const getReportCategoryTitle = () => {
    switch(subModuleId) {
      case 'financial': return 'Reportes Financieros';
      case 'clients': return 'Reportes de Clientes';
      case 'operations': return 'Reportes Operacionales';
      default: return 'Reportes';
    }
  };

  const reportTypes = getReportTypes();

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
    grid: {
      display: 'grid',
      gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))',
      gap: '1.5rem',
      marginBottom: '1.5rem'
    },
    card: {
      backgroundColor: 'white',
      borderRadius: '0.5rem',
      boxShadow: '0 1px 3px rgba(0, 0, 0, 0.1)',
      padding: '1.5rem',
      height: '100%'
    },
    cardTitle: {
      fontSize: '1rem',
      fontWeight: '600',
      color: '#0c4a6e', // coop-dark
      marginBottom: '1rem',
      display: 'flex',
      alignItems: 'center'
    },
    cardTitleIcon: {
      marginRight: '0.5rem',
      color: '#0ea5e9' // coop-primary
    },
    formGroup: {
      marginBottom: '1rem'
    },
    label: {
      display: 'block',
      fontSize: '0.875rem',
      fontWeight: '500',
      color: '#475569', // coop-neutral
      marginBottom: '0.5rem'
    },
    select: {
      width: '100%',
      padding: '0.5rem 0.75rem',
      borderRadius: '0.375rem',
      border: '1px solid #e5e7eb',
      backgroundColor: 'white',
      fontSize: '0.875rem',
      outline: 'none'
    },
    dateInput: {
      width: '100%',
      padding: '0.5rem 0.75rem',
      borderRadius: '0.375rem',
      border: '1px solid #e5e7eb',
      backgroundColor: 'white',
      fontSize: '0.875rem',
      outline: 'none'
    },
    button: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: '100%',
      padding: '0.625rem 1rem',
      backgroundColor: '#0ea5e9', // coop-primary
      color: 'white',
      borderRadius: '0.375rem',
      fontWeight: '500',
      fontSize: '0.875rem',
      border: 'none',
      cursor: 'pointer',
      transition: 'background-color 150ms'
    },
    buttonDisabled: {
      backgroundColor: '#93c5fd', // blue-300
      cursor: 'not-allowed'
    },
    buttonIcon: {
      marginRight: '0.5rem'
    },
    searchContainer: {
      position: 'relative',
      marginBottom: '1rem'
    },
    searchInput: {
      width: '100%',
      padding: '0.5rem 0.75rem 0.5rem 2.5rem',
      borderRadius: '0.375rem',
      border: '1px solid #e5e7eb',
      backgroundColor: 'white',
      fontSize: '0.875rem',
      outline: 'none'
    },
    searchIcon: {
      position: 'absolute',
      left: '0.75rem',
      top: '0.625rem',
      color: '#9ca3af' // gray-400
    },
    reportList: {
      listStyle: 'none',
      padding: 0,
      margin: 0
    },
    reportItem: {
      display: 'flex',
      justifyContent: 'space-between',
      padding: '0.75rem 0',
      borderBottom: '1px solid #f3f4f6',
      fontSize: '0.875rem',
      color: '#334155' // coop-neutral
    },
    reportName: {
      fontWeight: '500',
      color: '#0ea5e9', // coop-primary
      cursor: 'pointer'
    },
    reportDate: {
      fontSize: '0.75rem',
      color: '#6b7280' // gray-500
    },
    reportBadge: {
      display: 'inline-flex',
      alignItems: 'center',
      padding: '0.25rem 0.5rem',
      backgroundColor: '#f3f4f6',
      borderRadius: '0.375rem',
      fontSize: '0.75rem',
      color: '#4b5563', // gray-600
      fontWeight: '500'
    },
    emptyState: {
      textAlign: 'center',
      padding: '1.5rem',
      color: '#6b7280' // gray-500
    },
    reportTypeCard: {
      border: '1px solid #e5e7eb',
      borderRadius: '0.375rem',
      padding: '1rem',
      marginBottom: '0.5rem',
      cursor: 'pointer',
      transition: 'all 150ms'
    },
    reportTypeCardSelected: {
      borderColor: '#0ea5e9', // coop-primary
      backgroundColor: 'rgba(14, 165, 233, 0.05)'
    },
    reportTypeCardContent: {
      display: 'flex',
      alignItems: 'center'
    },
    reportTypeIcon: {
      marginRight: '0.75rem',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: '2rem',
      height: '2rem',
      backgroundColor: 'rgba(14, 165, 233, 0.1)',
      borderRadius: '0.375rem',
      color: '#0ea5e9' // coop-primary
    },
    reportTypeName: {
      fontWeight: '500',
      color: '#334155' // coop-neutral
    }
  };

  return (
    <div style={styles.container}>
      <h2 style={styles.header}>{getReportCategoryTitle()}</h2>
      
      <div style={styles.grid}>
        {/* Panel de Generación de Reportes */}
        <div style={styles.card}>
          <h3 style={styles.cardTitle}>
            <span style={styles.cardTitleIcon}>
              <Icon name="FileText" size={18} />
            </span>
            Generar Nuevo Reporte
          </h3>
          
          <div style={styles.formGroup}>
            <label style={styles.label}>Tipo de Reporte</label>
            <div style={{ maxHeight: '200px', overflowY: 'auto' }}>
              {reportTypes.map(type => (
                <div 
                  key={type.id}
                  style={{
                    ...styles.reportTypeCard,
                    ...(selectedReportType === type.id ? styles.reportTypeCardSelected : {})
                  }}
                  onClick={() => setSelectedReportType(type.id)}
                >
                  <div style={styles.reportTypeCardContent}>
                    <div style={styles.reportTypeIcon}>
                      <Icon name={type.icon} size={16} />
                    </div>
                    <div style={styles.reportTypeName}>{type.name}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
          
          <div style={styles.formGroup}>
            <label style={styles.label}>Fecha Inicial</label>
            <input
              type="date"
              value={dateRange.start}
              onChange={(e) => setDateRange({ ...dateRange, start: e.target.value })}
              style={styles.dateInput}
            />
          </div>
          
          <div style={styles.formGroup}>
            <label style={styles.label}>Fecha Final</label>
            <input
              type="date"
              value={dateRange.end}
              onChange={(e) => setDateRange({ ...dateRange, end: e.target.value })}
              style={styles.dateInput}
            />
          </div>
          
          <div style={styles.formGroup}>
            <label style={styles.label}>Formato</label>
            <select style={styles.select} defaultValue="pdf">
              <option value="pdf">PDF</option>
              <option value="xlsx">Excel (XLSX)</option>
              <option value="csv">CSV</option>
            </select>
          </div>
          
          <button 
            style={{
              ...styles.button,
              ...(isGenerating || !selectedReportType ? styles.buttonDisabled : {})
            }}
            onClick={handleGenerateReport}
            disabled={isGenerating || !selectedReportType}
          >
            <span style={styles.buttonIcon}>
              <Icon name={isGenerating ? "Loader" : "FileDown"} size={16} />
            </span>
            {isGenerating ? 'Generando...' : 'Generar Reporte'}
          </button>
        </div>
        
        {/* Panel de Reportes Recientes */}
        <div style={styles.card}>
          <h3 style={styles.cardTitle}>
            <span style={styles.cardTitleIcon}>
              <Icon name="Clock" size={18} />
            </span>
            Reportes Recientes
          </h3>
          
          <div style={styles.searchContainer}>
            <input
              type="text"
              placeholder="Buscar reporte..."
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              style={styles.searchInput}
            />
            <div style={styles.searchIcon}>
              <Icon name="Search" size={16} />
            </div>
          </div>
          
          {filteredReports.length > 0 ? (
            <ul style={styles.reportList}>
              {filteredReports.map(report => (
                <li key={report.id} style={styles.reportItem}>
                  <div>
                    <div style={styles.reportName}>{report.name}</div>
                    <div style={styles.reportDate}>{formatDate(report.date)}</div>
                  </div>
                  <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                    <span style={styles.reportBadge}>{report.format}</span>
                    <button style={{
                      backgroundColor: 'transparent',
                      border: 'none',
                      color: '#0ea5e9', // coop-primary
                      cursor: 'pointer',
                      padding: '0.25rem',
                      borderRadius: '0.25rem',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center'
                    }}>
                      <Icon name="Download" size={16} />
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <div style={styles.emptyState}>
              No se encontraron reportes recientes.
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default ReportsWindow;