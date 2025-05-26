// src/components/UI/ElegantIcon.jsx
import React from "react";
import * as FaIcons from "react-icons/fa";

const ElegantIcon = ({ name = "FaBox", size = 24, color = "#fff" }) => {
  const IconComponent = FaIcons[name] || FaIcons.FaBox;
  return <IconComponent size={size} color={color} />;
};

export default ElegantIcon;
