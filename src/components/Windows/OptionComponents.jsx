// src/components/Windows/OptionComponents.jsx
import React, { useState, useEffect, useCallback } from 'react';
import { adminService } from '../../services/apiService';
import Icon from '../UI/Icon';

// ✅ Componente OptionForm independiente
const OptionForm = React.memo(({
    editingOption,
    icons,
    submenus,
    loading,
    onSave,
    onCancel,
    showMessage
}) => {
    console.log('🟣 OptionForm render - editingOption:', editingOption?.opc_id || 'null');

    // Estado del formulario con inicialización inmediata
    const [formData, setFormData] = useState(() => {
        if (editingOption) {
            console.log('🟢 Inicializando opción con datos existentes');
            return {
                opc_nom: editingOption.opc_nom || '',
                ico_id: editingOption.ico_id || '',
                opc_componente: editingOption.opc_componente || '',
                opc_eje: editingOption.opc_eje || 1,
                opc_ventana_directa: Boolean(editingOption.opc_ventana_directa),
                submenu_ids: editingOption.submenus?.map(s => s.sub_id) || []
            };
        } else {
            console.log('🟡 Inicializando formulario de opción vacío');
            return {
                opc_nom: '',
                ico_id: '',
                opc_componente: '',
                opc_eje: 1,
                opc_ventana_directa: false,
                submenu_ids: []
            };
        }
    });

    // Solo actualizar cuando cambie editingOption
    useEffect(() => {
        console.log('🔄 OptionForm useEffect - editingOption cambió:', editingOption?.opc_id || 'null');

        if (editingOption) {
            setFormData({
                opc_nom: editingOption.opc_nom || '',
                ico_id: editingOption.ico_id || '',
                opc_componente: editingOption.opc_componente || '',
                opc_eje: editingOption.opc_eje || 1,
                opc_ventana_directa: Boolean(editingOption.opc_ventana_directa),
                submenu_ids: editingOption.submenus?.map(s => s.sub_id) || []
            });
        } else {
            setFormData({
                opc_nom: '',
                ico_id: '',
                opc_componente: '',
                opc_eje: 1,
                opc_ventana_directa: false,
                submenu_ids: []
            });
        }
    }, [editingOption?.opc_id]);

    // Manejadores estables
    const handleInputChange = useCallback((field, value) => {
        console.log('⌨️ Escribiendo en opción:', field, '=', value);
        setFormData(prev => ({
            ...prev,
            [field]: value
        }));
    }, []);

    const handleSubmenuToggle = useCallback((submenuId) => {
        console.log('🔀 Toggle submenú:', submenuId);
        setFormData(prev => {
            const currentSubmenus = prev.submenu_ids || [];
            const newSubmenus = currentSubmenus.includes(submenuId)
                ? currentSubmenus.filter(id => id !== submenuId)
                : [...currentSubmenus, submenuId];

            console.log('📋 Nuevos submenús seleccionados:', newSubmenus);
            return {
                ...prev,
                submenu_ids: newSubmenus
            };
        });
    }, []);

    const handleSubmit = useCallback(async (e) => {
        e.preventDefault();

        if (!formData.opc_nom?.trim()) {
            showMessage('error', 'El nombre de la opción es requerido');
            return;
        }

        if (!formData.submenu_ids || formData.submenu_ids.length === 0) {
            showMessage('error', 'Debe seleccionar al menos un submenú');
            return;
        }

        const dataToSend = {
            ...formData,
            opc_nom: formData.opc_nom.trim(),
            ico_id: formData.ico_id || null,
            opc_componente: formData.opc_componente?.trim() || null,
            opc_eje: parseInt(formData.opc_eje) || 1,
            opc_ventana_directa: Boolean(formData.opc_ventana_directa),
            submenu_ids: formData.submenu_ids
        };

        console.log('📤 Enviando datos de opción:', dataToSend);
        onSave(dataToSend, editingOption);
    }, [formData, editingOption, onSave, showMessage]);

    return (
        <div className="bg-white p-6 rounded-lg border border-gray-200 mb-4">
            <div className="flex justify-between items-center mb-4">
                <h3 className="text-lg font-semibold">
                    {editingOption ? `Editar Opción #${editingOption.opc_id}` : 'Crear Nueva Opción'}
                </h3>
                <div className="text-xs text-gray-500">
                    Submenús: [{formData.submenu_ids?.join(', ') || 'ninguno'}]
                </div>
            </div>

            <form onSubmit={handleSubmit}>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Nombre de la Opción *
                        </label>
                        <input
                            type="text"
                            value={formData.opc_nom || ''}
                            onChange={(e) => {
                                console.log('🖊️ Input opción onChange:', e.target.value);
                                handleInputChange('opc_nom', e.target.value);
                            }}
                            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Ingrese el nombre de la opción"
                            disabled={loading}
                            autoComplete="off"
                        />
                        <div className="text-xs text-gray-400 mt-1">
                            Valor: "{formData.opc_nom}"
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Icono
                        </label>
                        <select
                            value={formData.ico_id || ''}
                            onChange={(e) => handleInputChange('ico_id', e.target.value)}
                            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            disabled={loading}
                        >
                            <option value="">Seleccionar icono</option>
                            {icons.map((icon) => (
                                <option key={icon.id || icon.ico_id} value={icon.id || icon.ico_id}>
                                    {icon.nombre || icon.ico_nom} ({icon.categoria || icon.ico_cat})
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Componente
                        </label>
                        <input
                            type="text"
                            value={formData.opc_componente || ''}
                            onChange={(e) => handleInputChange('opc_componente', e.target.value)}
                            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Ej: ClientRegistry"
                            disabled={loading}
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Orden de Ejecución
                        </label>
                        <input
                            type="number"
                            min="1"
                            max="9"
                            value={formData.opc_eje || 1}
                            onChange={(e) => handleInputChange('opc_eje', parseInt(e.target.value) || 1)}
                            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            disabled={loading}
                        />
                    </div>
                </div>

                <div className="mt-4">
                    <label className="flex items-center">
                        <input
                            type="checkbox"
                            checked={Boolean(formData.opc_ventana_directa)}
                            onChange={(e) => handleInputChange('opc_ventana_directa', e.target.checked)}
                            className="mr-2"
                            disabled={loading}
                        />
                        <span className="text-sm text-gray-700">Ventana Directa</span>
                    </label>
                </div>

                {/* ✅ Sección de Asignación de Submenús */}
                <div className="mt-6 p-4 bg-gray-50 rounded-lg">
                    <h4 className="font-medium text-gray-700 mb-3">
                        Asignar a Submenús * ({formData.submenu_ids?.length || 0} seleccionados)
                    </h4>

                    {submenus.length === 0 ? (
                        <div className="text-center py-4 text-gray-500">
                            <Icon name="AlertCircle" size={16} className="mx-auto mb-2" />
                            <p className="text-sm">No hay submenús disponibles</p>
                            <p className="text-xs text-gray-400 mt-1">Debe crear submenús primero</p>
                        </div>
                    ) : (
                        <div className="space-y-3 max-h-64 overflow-y-auto">
                            {submenus.map(submenu => (
                                <div key={submenu.sub_id} className="border-b border-gray-200 pb-3 last:border-b-0">
                                    <label
                                        className={`flex items-start p-3 rounded cursor-pointer transition-colors ${formData.submenu_ids?.includes(submenu.sub_id)
                                                ? 'bg-blue-100 border border-blue-300'
                                                : 'bg-white border border-gray-200 hover:bg-gray-50'
                                            }`}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={formData.submenu_ids?.includes(submenu.sub_id) || false}
                                            onChange={() => handleSubmenuToggle(submenu.sub_id)}
                                            className="mr-3 mt-0.5"
                                            disabled={loading}
                                        />
                                        <div className="flex-1">
                                            <div className="flex items-center mb-1">
                                                {submenu.ico_nombre && (
                                                    <Icon name={submenu.ico_nombre} size={14} className="mr-2 text-gray-500" />
                                                )}
                                                <span className="text-sm font-medium">{submenu.sub_nom}</span>
                                                <span className="ml-2 text-xs text-gray-500">#{submenu.sub_id}</span>
                                            </div>

                                            {/* Mostrar menús asociados al submenú */}
                                            {submenu.menus && submenu.menus.length > 0 && (
                                                <div className="flex flex-wrap gap-1 mt-1">
                                                    <span className="text-xs text-gray-400">Menús:</span>
                                                    {submenu.menus.map(menu => (
                                                        <span
                                                            key={menu.men_id}
                                                            className="px-2 py-0.5 bg-gray-200 text-gray-700 rounded text-xs"
                                                            title={`Menú ID: ${menu.men_id}`}
                                                        >
                                                            {menu.men_nom}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </label>
                                </div>
                            ))}
                        </div>
                    )}

                    {formData.submenu_ids?.length === 0 && submenus.length > 0 && (
                        <div className="mt-2 text-sm text-red-600 flex items-center">
                            <Icon name="AlertTriangle" size={14} className="mr-1" />
                            Debe seleccionar al menos un submenú
                        </div>
                    )}
                </div>

                <div className="flex gap-2 mt-6">
                    <button
                        type="submit"
                        disabled={loading || !formData.opc_nom?.trim() || !formData.submenu_ids?.length}
                        className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                    >
                        {loading ? 'Guardando...' : (editingOption ? 'Actualizar' : 'Crear')}
                    </button>
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={loading}
                        className="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    );
});

OptionForm.displayName = 'OptionForm';

// ✅ Componente de Lista de Opciones
const OptionsList = React.memo(({
    options,
    loading,
    onNew,
    onEdit,
    onDelete,
    showMessage
}) => (
    <div className="bg-white rounded-lg border border-gray-200 p-4">
        <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-semibold">Lista de Opciones ({options.length})</h3>
            <button
                onClick={onNew}
                className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 flex items-center"
                disabled={loading}
            >
                <Icon name="Plus" size={16} className="mr-2" />
                Nueva Opción
            </button>
        </div>

        {options.length === 0 ? (
            <div className="text-center py-8 text-gray-500">
                <Icon name="Settings" size={48} className="mx-auto mb-4 text-gray-300" />
                <p>No hay opciones registradas</p>
            </div>
        ) : (
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-gray-200">
                            <th className="text-left py-2">ID</th>
                            <th className="text-left py-2">Nombre</th>
                            <th className="text-left py-2">Componente</th>
                            <th className="text-left py-2">Submenús Asignados</th>
                            <th className="text-left py-2">Ventana Directa</th>
                            <th className="text-left py-2">Estado</th>
                            <th className="text-left py-2">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        {options.map((option) => (
                            <tr key={option.opc_id} className="border-b border-gray-100 hover:bg-gray-50">
                                <td className="py-2 text-xs text-gray-500">#{option.opc_id}</td>
                                <td className="py-2 font-medium">{option.opc_nom}</td>
                                <td className="py-2">
                                    {option.opc_componente ? (
                                        <span className="px-2 py-1 bg-gray-100 text-gray-800 rounded text-xs font-mono">
                                            {option.opc_componente}
                                        </span>
                                    ) : (
                                        <span className="text-gray-400">-</span>
                                    )}
                                </td>
                                <td className="py-2">
                                    {option.submenus && option.submenus.length > 0 ? (
                                        <div className="flex flex-wrap gap-1">
                                            {option.submenus.map(submenu => (
                                                <span
                                                    key={submenu.sub_id}
                                                    className="px-2 py-1 bg-purple-100 text-purple-800 rounded-full text-xs"
                                                    title={`Submenú ID: ${submenu.sub_id}`}
                                                >
                                                    {submenu.sub_nom}
                                                </span>
                                            ))}
                                        </div>
                                    ) : (
                                        <span className="text-gray-400">Sin asignar</span>
                                    )}
                                </td>
                                <td className="py-2">
                                    {option.opc_ventana_directa ?
                                        <span className="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">Sí</span> :
                                        <span className="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs">No</span>
                                    }
                                </td>
                                <td className="py-2">
                                    <span className={`px-2 py-1 rounded-full text-xs ${option.opc_est ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                                        }`}>
                                        {option.opc_est ? 'Activo' : 'Inactivo'}
                                    </span>
                                </td>
                                <td className="py-2">
                                    <div className="flex gap-2">
                                        <button
                                            onClick={() => onEdit(option)}
                                            className="p-1 text-blue-600 hover:bg-blue-100 rounded"
                                            disabled={loading}
                                            title="Editar opción"
                                        >
                                            <Icon name="Edit" size={14} />
                                        </button>
                                        <button
                                            onClick={() => onDelete(option)}
                                            className="p-1 text-red-600 hover:bg-red-100 rounded"
                                            disabled={loading}
                                            title="Eliminar opción"
                                        >
                                            <Icon name="Trash" size={14} />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        )}
    </div>
));

OptionsList.displayName = 'OptionsList';

// ✅ Hook personalizado para gestión de opciones
const useOptionManagement = (showMessage, loadOptions) => {
    const [showOptionForm, setShowOptionForm] = useState(false);
    const [editingOption, setEditingOption] = useState(null);
    const [optionFormKey, setOptionFormKey] = useState(0);

    const handleOptionSave = useCallback(async (formData, editingOption) => {
        console.log('💾 Guardando opción:', formData);

        try {
            const cleanData = {
                opc_nom: formData.opc_nom?.trim(),
                ico_id: formData.ico_id ? parseInt(formData.ico_id) : null,
                opc_componente: formData.opc_componente?.trim() || null,
                opc_eje: parseInt(formData.opc_eje) || 1,
                opc_ventana_directa: Boolean(formData.opc_ventana_directa),
                submenu_ids: formData.submenu_ids || []
            };

            console.log('📤 Datos limpios de opción a enviar:', cleanData);

            let result;
            if (editingOption) {
                result = await adminService.options.update(editingOption.opc_id, cleanData);
                showMessage('success', 'Opción actualizada correctamente');
                console.log('✅ Opción actualizada:', result);
            } else {
                result = await adminService.options.create(cleanData);
                showMessage('success', 'Opción creada correctamente');
                console.log('✅ Opción creada:', result);
            }

            await loadOptions();
            setShowOptionForm(false);
            setEditingOption(null);
            setOptionFormKey(prev => prev + 1);

        } catch (error) {
            console.error('❌ Error completo opción:', error);
            console.error('❌ Error response opción:', error.response?.data);

            let errorMsg = 'Error al guardar la opción';

            if (error.response?.data?.message) {
                errorMsg = error.response.data.message;
            } else if (error.message) {
                errorMsg = error.message;
            }

            showMessage('error', errorMsg);
        }
    }, [showMessage, loadOptions]);

    const handleOptionCancel = useCallback(() => {
        console.log('❌ Cancelando formulario de opción');
        setShowOptionForm(false);
        setEditingOption(null);
        setOptionFormKey(prev => prev + 1);
    }, []);

    const handleNewOption = useCallback(() => {
        console.log('➕ Nueva opción');
        setEditingOption(null);
        setShowOptionForm(true);
        setOptionFormKey(prev => prev + 1);
    }, []);

    const handleEditOption = useCallback((option) => {
        console.log('✏️ Editar opción:', option.opc_id);
        setEditingOption(option);
        setShowOptionForm(true);
        setOptionFormKey(prev => prev + 1);
    }, []);

    const handleDeleteOption = useCallback(async (option) => {
        if (window.confirm(`¿Eliminar la opción "${option.opc_nom}"?`)) {
            try {
                await adminService.options.delete(option.opc_id);
                showMessage('success', 'Opción eliminada correctamente');
                await loadOptions();
            } catch (error) {
                console.error('Error eliminando opción:', error);
                const errorMsg = error.response?.data?.message || 'Error al eliminar la opción';
                showMessage('error', errorMsg);
            }
        }
    }, [showMessage, loadOptions]);

    return {
        showOptionForm,
        editingOption,
        optionFormKey,
        handleOptionSave,
        handleOptionCancel,
        handleNewOption,
        handleEditOption,
        handleDeleteOption
    };
};

// ✅ Exportar componentes y hook
export { OptionForm, OptionsList, useOptionManagement };