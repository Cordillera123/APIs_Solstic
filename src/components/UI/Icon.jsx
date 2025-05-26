import React from 'react';
import * as LucideIcons from 'lucide-react';

const Icon = ({ name, size = 24, className = '', ...props }) => {
  // Verificar si el nombre de ícono existe en Lucide
  const LucideIcon = LucideIcons[name];
  
  // Si existe, renderizarlo con las propiedades dadas
  if (LucideIcon) {
    return <LucideIcon size={size} className={className} {...props} />;
  }
  
  // Si no existe, mostrar un marcador de posición o un ícono genérico
  console.warn(`Ícono '${name}' no encontrado en lucide-react.`);
  return <LucideIcons.HelpCircle size={size} className={`${className} text-gray-400`} {...props} />;
};

export default Icon;