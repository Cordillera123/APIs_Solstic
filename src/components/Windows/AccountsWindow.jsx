import React, { useState } from 'react';
import Icon from '../UI/Icon'; // Asegurar que esta ruta es correcta

const AccountsWindow = ({ subModuleId }) => {
  const [filter, setFilter] = useState('');
  const [sortBy, setSortBy] = useState('account');
  const [sortDirection, setSortDirection] = useState('asc');

  // Datos de muestra de cuentas
  const accounts = [
    { id: 1, account: '10023456', type: 'Corriente', balance: 45678.90, status: 'Activa' },
    { id: 2, account: '20056789', type: 'Ahorros', balance: 123456.78, status: 'Activa' },
    { id: 3, account: '30078945', type: 'Inversión', balance: 78901.23, status: 'En revisión' },
    { id: 4, account: '40089012', type: 'Crédito', balance: -5432.10, status: 'Activa' },
    { id: 5, account: '50092345', type: 'Plazo Fijo', balance: 250000.00, status: 'Bloqueada' }
  ];

  // Filtrar cuentas
  const filteredAccounts = accounts.filter(account => 
    account.account.includes(filter) || 
    account.type.toLowerCase().includes(filter.toLowerCase()) ||
    account.status.toLowerCase().includes(filter.toLowerCase())
  );

  // Ordenar cuentas
  const sortedAccounts = [...filteredAccounts].sort((a, b) => {
    let comparison = 0;
    
    // Definir comparación según el campo seleccionado
    if (sortBy === 'account') {
      comparison = a.account.localeCompare(b.account);
    } else if (sortBy === 'type') {
      comparison = a.type.localeCompare(b.type);
    } else if (sortBy === 'balance') {
      comparison = a.balance - b.balance;
    } else if (sortBy === 'status') {
      comparison = a.status.localeCompare(b.status);
    }
    
    // Aplicar dirección del ordenamiento
    return sortDirection === 'asc' ? comparison : -comparison;
  });

  // Manejar cambio de orden
  const handleSort = (column) => {
    if (sortBy === column) {
      // Si ya estamos ordenando por esta columna, cambiar dirección
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      // Si es una columna nueva, establecerla como sortBy y reset a asc
      setSortBy(column);
      setSortDirection('asc');
    }
  };

  // Función para formatear moneda
  const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-EC', {
      style: 'currency',
      currency: 'USD'
    }).format(amount);
  };

  // Determinar el tipo de cuenta basado en subModuleId
  const getAccountTypeTitle = () => {
    if (subModuleId === 'savings') return 'Cuentas de Ahorro';
    if (subModuleId === 'credit') return 'Cuentas de Crédito';
    if (subModuleId === 'fixed-term') return 'Cuentas a Plazo Fijo';
    return 'Todas las Cuentas';
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
      justifyContent: 'space-between',
      marginBottom: '1rem',
      gap: '1rem'
    },
    searchContainer: {
      position: 'relative',
      flex: '1'
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
    sortable: {
      cursor: 'pointer',
      display: 'flex',
      alignItems: 'center'
    },
    sortIcon: {
      marginLeft: '0.25rem',
      fontSize: '0.875rem'
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
        case 'Activa':
          color = { bg: '#dcfce7', text: '#166534' }; // green
          break;
        case 'En revisión':
          color = { bg: '#fef9c3', text: '#854d0e' }; // yellow
          break;
        case 'Bloqueada':
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
    balancePositive: {
      color: '#10b981' // coop-success
    },
    balanceNegative: {
      color: '#ef4444' // coop-danger
    }
  };

  return (
    <div style={styles.container}>
      <h2 style={styles.header}>{getAccountTypeTitle()}</h2>
      
      <div style={styles.controls}>
        <div style={styles.searchContainer}>
          <input
            type="text"
            placeholder="Buscar cuenta..."
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            style={styles.searchInput}
          />
          <div style={styles.searchIcon}>
            <Icon name="Search" size={16} />
          </div>
        </div>
        
        <button style={styles.button}>
          <span style={styles.buttonIcon}>
            <Icon name="Plus" size={16} />
          </span>
          Nueva Cuenta
        </button>
      </div>
      
      <div style={styles.table}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead style={styles.tableHeader}>
            <tr>
              <th 
                style={{...styles.tableHeaderCell, ...styles.sortable}}
                onClick={() => handleSort('account')}
              >
                Cuenta
                {sortBy === 'account' && (
                  <span style={styles.sortIcon}>
                    {sortDirection === 'asc' ? '↑' : '↓'}
                  </span>
                )}
              </th>
              <th 
                style={{...styles.tableHeaderCell, ...styles.sortable}}
                onClick={() => handleSort('type')}
              >
                Tipo
                {sortBy === 'type' && (
                  <span style={styles.sortIcon}>
                    {sortDirection === 'asc' ? '↑' : '↓'}
                  </span>
                )}
              </th>
              <th 
                style={{...styles.tableHeaderCell, ...styles.sortable}}
                onClick={() => handleSort('balance')}
              >
                Balance
                {sortBy === 'balance' && (
                  <span style={styles.sortIcon}>
                    {sortDirection === 'asc' ? '↑' : '↓'}
                  </span>
                )}
              </th>
              <th 
                style={{...styles.tableHeaderCell, ...styles.sortable}}
                onClick={() => handleSort('status')}
              >
                Estado
                {sortBy === 'status' && (
                  <span style={styles.sortIcon}>
                    {sortDirection === 'asc' ? '↑' : '↓'}
                  </span>
                )}
              </th>
              <th style={styles.tableHeaderCell}>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {sortedAccounts.map(account => (
              <tr key={account.id} style={styles.tableRow}>
                <td style={styles.tableCell}>{account.account}</td>
                <td style={styles.tableCell}>{account.type}</td>
                <td style={{
                  ...styles.tableCell, 
                  ...(account.balance >= 0 ? styles.balancePositive : styles.balanceNegative)
                }}>
                  {formatCurrency(account.balance)}
                </td>
                <td style={styles.tableCell}>
                  <span style={styles.statusBadge(account.status)}>
                    {account.status}
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
                      <Icon name="Edit" size={16} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
            
            {filteredAccounts.length === 0 && (
              <tr>
                <td colSpan="5" style={{
                  padding: '2rem', 
                  textAlign: 'center',
                  color: '#6b7280' // gray-500
                }}>
                  No se encontraron cuentas que coincidan con el filtro.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default AccountsWindow;
