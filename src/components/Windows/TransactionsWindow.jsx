import React, { useState } from 'react';
import Icon from '../UI/Icon'; // Asegurar que esta ruta es correcta

const TransactionsWindow = ({ subModuleId }) => {
  const [filter, setFilter] = useState('');
  const [dateRange, setDateRange] = useState({ start: '', end: '' });
  const [transactionType, setTransactionType] = useState('all');

  // Datos de muestra de transacciones
  const transactions = [
    { id: 'TX-78901', date: '2025-05-01', account: '10023456', amount: 2500.00, type: 'Depósito', status: 'Completada' },
    { id: 'TX-78902', date: '2025-05-02', account: '20056789', amount: -1200.00, type: 'Retiro', status: 'Completada' },
    { id: 'TX-78903', date: '2025-05-04', account: '10023456', amount: -5000.00, type: 'Transferencia', status: 'Pendiente' },
    { id: 'TX-78904', date: '2025-05-05', account: '30078945', amount: 10000.00, type: 'Depósito', status: 'Completada' },
    { id: 'TX-78905', date: '2025-05-05', account: '20056789', amount: -750.00, type: 'Pago', status: 'Completada' }
  ];

  // Filtrar transacciones
  const getFilteredTransactions = () => {
    return transactions.filter(transaction => {
      // Filtro de texto
      const textMatch = 
        transaction.id.toLowerCase().includes(filter.toLowerCase()) ||
        transaction.account.includes(filter) ||
        transaction.type.toLowerCase().includes(filter.toLowerCase()) ||
        transaction.status.toLowerCase().includes(filter.toLowerCase());
      
      // Filtro de tipo de transacción
      const typeMatch = 
        transactionType === 'all' || 
        (transactionType === 'deposit' && transaction.amount > 0) ||
        (transactionType === 'withdrawal' && transaction.amount < 0);
      
      // Filtro de fecha
      const dateMatch = 
        (!dateRange.start || new Date(transaction.date) >= new Date(dateRange.start)) &&
        (!dateRange.end || new Date(transaction.date) <= new Date(dateRange.end));
      
      return textMatch && typeMatch && dateMatch;
    });
  };

  const filteredTransactions = getFilteredTransactions();

  // Función para formatear moneda
  const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-EC', {
      style: 'currency',
      currency: 'USD'
    }).format(amount);
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
  const getTransactionTypeTitle = () => {
    if (subModuleId === 'deposits') return 'Depósitos';
    if (subModuleId === 'withdrawals') return 'Retiros';
    if (subModuleId === 'transfers') return 'Transferencias';
    return 'Todas las Transacciones';
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
    controls: {
      display: 'flex',
      flexDirection: 'column',
      gap: '1rem',
      marginBottom: '1.5rem'
    },
    filtersRow: {
      display: 'flex',
      gap: '1rem',
      flexWrap: 'wrap'
    },
    searchContainer: {
      position: 'relative',
      flex: '1',
      minWidth: '200px'
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
    dateInput: {
      padding: '0.5rem 0.75rem',
      borderRadius: '0.375rem',
      border: '1px solid #e5e7eb',
      backgroundColor: 'white',
      fontSize: '0.875rem',
      outline: 'none',
      width: '150px'
    },
    select: {
      padding: '0.5rem 0.75rem',
      borderRadius: '0.375rem',
      border: '1px solid #e5e7eb',
      backgroundColor: 'white',
      fontSize: '0.875rem',
      outline: 'none',
      minWidth: '150px'
    },
    button: {
      display: 'flex',
      alignItems: 'center',
      padding: '0.5rem 1rem',
      backgroundColor: '#0ea5e9', // coop-primary
      color: 'white',
      borderRadius: '0.375rem',
      fontWeight: '500',
      fontSize: '0.875rem',
      border: 'none',
      cursor: 'pointer'
    },
    buttonIcon: {
      marginRight: '0.5rem'
    },
    actionsRow: {
      display: 'flex',
      justifyContent: 'space-between',
      flexWrap: 'wrap',
      gap: '1rem'
    },
    table: {
      width: '100%',
      backgroundColor: 'white',
      borderRadius: '0.5rem',
      overflow: 'hidden',
      boxShadow: '0 1px 3px rgba(0, 0, 0, 0.1)'
    },
    tableHeader: {
      backgroundColor: '#f9fafb', // gray-50
      userSelect: 'none'
    },
    tableHeaderCell: {
      padding: '0.75rem 1rem',
      textAlign: 'left',
      fontSize: '0.75rem',
      fontWeight: '600',
      color: '#475569', // coop-neutral
      textTransform: 'uppercase',
      letterSpacing: '0.05em'
    },
    tableCell: {
      padding: '0.75rem 1rem',
      fontSize: '0.875rem',
      borderTop: '1px solid #f3f4f6',
      color: '#334155' // coop-neutral
    },
    tableRow: {
      transition: 'background-color 150ms',
      ':hover': {
        backgroundColor: '#f9fafb' // gray-50
      }
    },
    statusBadge: (status) => {
      let color;
      switch (status) {
        case 'Completada':
          color = { bg: '#dcfce7', text: '#166534' }; // green
          break;
        case 'Pendiente':
          color = { bg: '#fef9c3', text: '#854d0e' }; // yellow
          break;
        case 'Rechazada':
          color = { bg: '#fee2e2', text: '#b91c1c' }; // red
          break;
        default:
          color = { bg: '#e5e7eb', text: '#4b5563' }; // gray
      }
      
      return {
        padding: '0.25rem 0.5rem',
        fontSize: '0.75rem',
        borderRadius: '9999px',
        backgroundColor: color.bg,
        color: color.text,
        display: 'inline-block'
      };
    },
    amountPositive: {
      color: '#10b981' // coop-success
    },
    amountNegative: {
      color: '#ef4444' // coop-danger
    },
    emptyState: {
      padding: '2rem',
      textAlign: 'center',
      color: '#6b7280' // gray-500
    }
  };

  return (
    <div style={styles.container}>
      <h2 style={styles.header}>{getTransactionTypeTitle()}</h2>
      
      <div style={styles.controls}>
        <div style={styles.filtersRow}>
          <div style={styles.searchContainer}>
            <input
              type="text"
              placeholder="Buscar transacción..."
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              style={styles.searchInput}
            />
            <div style={styles.searchIcon}>
              <Icon name="Search" size={16} />
            </div>
          </div>
          
          <div>
            <select
              value={transactionType}
              onChange={(e) => setTransactionType(e.target.value)}
              style={styles.select}
            >
              <option value="all">Todos los tipos</option>
              <option value="deposit">Solo depósitos</option>
              <option value="withdrawal">Solo retiros</option>
            </select>
          </div>
        </div>
        
        <div style={styles.filtersRow}>
          <div>
            <label style={{ fontSize: '0.875rem', marginRight: '0.5rem', color: '#475569' }}>
              Desde:
            </label>
            <input
              type="date"
              value={dateRange.start}
              onChange={(e) => setDateRange({ ...dateRange, start: e.target.value })}
              style={styles.dateInput}
            />
          </div>
          
          <div>
            <label style={{ fontSize: '0.875rem', marginRight: '0.5rem', color: '#475569' }}>
              Hasta:
            </label>
            <input
              type="date"
              value={dateRange.end}
              onChange={(e) => setDateRange({ ...dateRange, end: e.target.value })}
              style={styles.dateInput}
            />
          </div>
          
          <div style={{ marginLeft: 'auto' }}>
            <button style={styles.button}>
              <span style={styles.buttonIcon}>
                <Icon name="Plus" size={16} />
              </span>
              Nueva Transacción
            </button>
          </div>
        </div>
      </div>
      
      <div style={styles.table}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead style={styles.tableHeader}>
            <tr>
              <th style={styles.tableHeaderCell}>ID Trans.</th>
              <th style={styles.tableHeaderCell}>Fecha</th>
              <th style={styles.tableHeaderCell}>Cuenta</th>
              <th style={styles.tableHeaderCell}>Monto</th>
              <th style={styles.tableHeaderCell}>Tipo</th>
              <th style={styles.tableHeaderCell}>Estado</th>
              <th style={styles.tableHeaderCell}>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {filteredTransactions.map(transaction => (
              <tr key={transaction.id} style={styles.tableRow}>
                <td style={styles.tableCell}>{transaction.id}</td>
                <td style={styles.tableCell}>{formatDate(transaction.date)}</td>
                <td style={styles.tableCell}>{transaction.account}</td>
                <td style={{
                  ...styles.tableCell, 
                  ...(transaction.amount >= 0 ? styles.amountPositive : styles.amountNegative)
                }}>
                  {formatCurrency(transaction.amount)}
                </td>
                <td style={styles.tableCell}>{transaction.type}</td>
                <td style={styles.tableCell}>
                  <span style={styles.statusBadge(transaction.status)}>
                    {transaction.status}
                  </span>
                </td>
                <td style={styles.tableCell}>
                  <div style={{ display: 'flex', gap: '0.5rem' }}>
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
                      <Icon name="Eye" size={16} />
                    </button>
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
                      <Icon name="Printer" size={16} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
            
            {filteredTransactions.length === 0 && (
              <tr>
                <td colSpan="7" style={styles.emptyState}>
                  No se encontraron transacciones que coincidan con los filtros.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default TransactionsWindow;