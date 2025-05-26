// src/components/Windows/SubmenuComponents.jsx
import React, { useState, useEffect, useCallback } from 'react';
import { adminService } from '../../services/apiService';
import Icon from '../UI/Icon';

// ✅ Componente SubmenuForm independiente
const SubmenuForm = React.memo(({
    editingSubmenu,
    icons,
    menus,
    loading,
    onSave,
    onCancel,
    showMessage
}) => {
    console.log('🔵 SubmenuForm render - editingSubmenu:', editingSubmenu?.sub_id || 'null');

    // Estado del formulario con inicialización inmediata
    const [formData, setFormData] = useState(() => {
        if (editingSubmenu) {
            console.log('🟢 Inicializando submenú con datos existentes');
            return {
                sub_nom: editingSubmenu.sub_nom || '',
                ico_id: editingSubmenu.ico_id || '',
                sub_componente: editingSubmenu.sub_componente || '',
                sub_eje: editingSubmenu.sub_eje || 1,
                sub_ventana_directa: Boolean(editingSubmenu.sub_ventana_directa),
                menu_ids: editingSubmenu.menus?.map(m => m.men_id) || []
            };
        } else {
            console.log('🟡 Inicializando formulario de submenú vacío');
            return {
                sub_nom: '',
                ico_id: '',
                sub_componente: '',
                sub_eje: 1,
                sub_ventana_directa: false,
                menu_ids: []
            };
        }
    });

    // Solo actualizar cuando cambie editingSubmenu
    useEffect(() => {
        console.log('🔄 SubmenuForm useEffect - editingSubmenu cambió:', editingSubmenu?.sub_id || 'null');

        if (editingSubmenu) {
            setFormData({
                sub_nom: editingSubmenu.sub_nom || '',
                ico_id: editingSubmenu.ico_id || '',
                sub_componente: editingSubmenu.sub_componente || '',
                sub_eje: editingSubmenu.sub_eje || 1,
                sub_ventana_directa: Boolean(editingSubmenu.sub_ventana_directa),
                menu_ids: editingSubmenu.menus?.map(m => m.men_id) || []
            });
        } else {
            setFormData({
                sub_nom: '',
                ico_id: '',
                sub_componente: '',
                sub_eje: 1,
                sub_ventana_directa: false,
                menu_ids: []
            });
        }
    }, [editingSubmenu?.sub_id]);

    // Manejadores estables
    const handleInputChange = useCallback((field, value) => {
        console.log('⌨️ Escribiendo en submenú:', field, '=', value);
        setFormData(prev => ({
            ...prev,
            [field]: value
        }));
    }, []);

    const handleMenuToggle = useCallback((menuId) => {
        console.log('🔀 Toggle menú:', menuId);
        setFormData(prev => {
            const currentMenus = prev.menu_ids || [];
            const newMenus = currentMenus.includes(menuId)
                ? currentMenus.filter(id => id !== menuId)
                : [...currentMenus, menuId];

            console.log('📋 Nuevos menús seleccionados:', newMenus);
            return {
                ...prev,
                menu_ids: newMenus
            };
        });
    }, []);

    const handleSubmit = useCallback(async (e) => {
        e.preventDefault();

        if (!formData.sub_nom?.trim()) {
            showMessage('error', 'El nombre del submenú es requerido');
            return;
        }

        if (!formData.menu_ids || formData.menu_ids.length === 0) {
            showMessage('error', 'Debe seleccionar al menos un menú');
            return;
        }

        const dataToSend = {
            ...formData,
            sub_nom: formData.sub_nom.trim(),
            ico_id: formData.ico_id || null,
            sub_componente: formData.sub_componente?.trim() || null,
            sub_eje: parseInt(formData.sub_eje) || 1,
            sub_ventana_directa: Boolean(formData.sub_ventana_directa),
            menu_ids: formData.menu_ids
        };

        console.log('📤 Enviando datos de submenú:', dataToSend);
        onSave(dataToSend, editingSubmenu);
    }, [formData, editingSubmenu, onSave, showMessage]);

    return (
        <div className="bg-white p-6 rounded-lg border border-gray-200 mb-4">
            <div className="flex justify-between items-center mb-4">
                <h3 className="text-lg font-semibold">
                    {editingSubmenu ? `Editar Submenú #${editingSubmenu.sub_id}` : 'Crear Nuevo Submenú'}
                </h3>
                <div className="text-xs text-gray-500">
                    Menús: [{formData.menu_ids?.join(', ') || 'ninguno'}]
                </div>
            </div>

            <form onSubmit={handleSubmit}>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Nombre del Submenú *
                        </label>
                        <input
                            type="text"
                            value={formData.sub_nom || ''}
                            onChange={(e) => {
                                console.log('🖊️ Input submenú onChange:', e.target.value);
                                handleInputChange('sub_nom', e.target.value);
                            }}
                            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Ingrese el nombre del submenú"
                            disabled={loading}
                            autoComplete="off"
                        />
                        <div className="text-xs text-gray-400 mt-1">
                            Valor: "{formData.sub_nom}"
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
                            value={formData.sub_componente || ''}
                            onChange={(e) => handleInputChange('sub_componente', e.target.value)}
                            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Ej: ClientsWindow"
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
                            value={formData.sub_eje || 1}
                            onChange={(e) => handleInputChange('sub_eje', parseInt(e.target.value) || 1)}
                            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            disabled={loading}
                        />
                    </div>
                </div>

                <div className="mt-4">
                    <label className="flex items-center">
                        <input
                            type="checkbox"
                            checked={Boolean(formData.sub_ventana_directa)}
                            onChange={(e) => handleInputChange('sub_ventana_directa', e.target.checked)}
                            className="mr-2"
                            disabled={loading}
                        />
                        <span className="text-sm text-gray-700">Ventana Directa</span>
                    </label>
                </div>

                {/* ✅ Sección de Asignación de Menús */}
                <div className="mt-6 p-4 bg-gray-50 rounded-lg">
                    <h4 className="font-medium text-gray-700 mb-3">
                        Asignar a Menús * ({formData.menu_ids?.length || 0} seleccionados)
                    </h4>

                    {menus.length === 0 ? (
                        <div className="text-center py-4 text-gray-500">
                            <Icon name="AlertCircle" size={16} className="mx-auto mb-2" />
                            <p className="text-sm">No hay menús disponibles</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-2 max-h-48 overflow-y-auto">
                            {menus.map(menu => (
                                <label
                                    key={menu.men_id}
                                    className={`flex items-center p-2 rounded cursor-pointer transition-colors ${formData.menu_ids?.includes(menu.men_id)
                                            ? 'bg-blue-100 border border-blue-300'
                                            : 'bg-white border border-gray-200 hover:bg-gray-50'
                                        }`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={formData.menu_ids?.includes(menu.men_id) || false}
                                        onChange={() => handleMenuToggle(menu.men_id)}
                                        className="mr-3"
                                        disabled={loading}
                                    />
                                    <div className="flex items-center flex-1">
                                        {menu.ico_nombre && (
                                            <Icon name={menu.ico_nombre} size={14} className="mr-2 text-gray-500" />
                                        )}
                                        <span className="text-sm font-medium">{menu.men_nom}</span>
                                        <span className="ml-2 text-xs text-gray-500">#{menu.men_id}</span>
                                    </div>
                                </label>
                            ))}
                        </div>
                    )}

                    {formData.menu_ids?.length === 0 && (
                        <div className="mt-2 text-sm text-red-600 flex items-center">
                            <Icon name="AlertTriangle" size={14} className="mr-1" />
                            Debe seleccionar al menos un menú
                        </div>
                    )}
                </div>

                <div className="flex gap-2 mt-6">
                    <button
                        type="submit"
                        disabled={loading || !formData.sub_nom?.trim() || !formData.menu_ids?.length}
                        className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                    >
                        {loading ? 'Guardando...' : (editingSubmenu ? 'Actualizar' : 'Crear')}
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

SubmenuForm.displayName = 'SubmenuForm';

// ✅ Componente de Lista de Submenús
const SubmenusList = React.memo(({
    submenus,
    loading,
    onNew,
    onEdit,
    onDelete,
    showMessage
}) => (
    <div className="bg-white rounded-lg border border-gray-200 p-4">
        <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-semibold">Lista de Submenús ({submenus.length})</h3>
            <button
                onClick={onNew}
                className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 flex items-center"
                disabled={loading}
            >
                <Icon name="Plus" size={16} className="mr-2" />
                Nuevo Submenú
            </button>
        </div>

        {submenus.length === 0 ? (
            <div className="text-center py-8 text-gray-500">
                <Icon name="Layers" size={48} className="mx-auto mb-4 text-gray-300" />
                <p>No hay submenús registrados</p>
            </div>
        ) : (
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-gray-200">
                            <th className="text-left py-2">ID</th>
                            <th className="text-left py-2">Nombre</th>
                            <th className="text-left py-2">Componente</th>
                            <th className="text-left py-2">Menús Asignados</th>
                            <th className="text-left py-2">Ventana Directa</th>
                            <th className="text-left py-2">Estado</th>
                            <th className="text-left py-2">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        {submenus.map((submenu) => (
                            <tr key={submenu.sub_id} className="border-b border-gray-100 hover:bg-gray-50">
                                <td className="py-2 text-xs text-gray-500">#{submenu.sub_id}</td>
                                <td className="py-2 font-medium">{submenu.sub_nom}</td>
                                <td className="py-2">
                                    {submenu.sub_componente ? (
                                        <span className="px-2 py-1 bg-gray-100 text-gray-800 rounded text-xs font-mono">
                                            {submenu.sub_componente}
                                        </span>
                                    ) : (
                                        <span className="text-gray-400">-</span>
                                    )}
                                </td>
                                <td className="py-2">
                                    {submenu.menus && submenu.menus.length > 0 ? (
                                        <div className="flex flex-wrap gap-1">
                                            {submenu.menus.map(menu => (
                                                <span
                                                    key={menu.men_id}
                                                    className="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs"
                                                    title={`Menú ID: ${menu.men_id}`}
                                                >
                                                    {menu.men_nom}
                                                </span>
                                            ))}
                                        </div>
                                    ) : (
                                        <span className="text-gray-400">Sin asignar</span>
                                    )}
                                </td>
                                <td className="py-2">
                                    {submenu.sub_ventana_directa ?
                                        <span className="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">Sí</span> :
                                        <span className="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs">No</span>
                                    }
                                </td>
                                <td className="py-2">
                                    <span className={`px-2 py-1 rounded-full text-xs ${submenu.sub_est ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                                        }`}>
                                        {submenu.sub_est ? 'Activo' : 'Inactivo'}
                                    </span>
                                </td>
                                <td className="py-2">
                                    <div className="flex gap-2">
                                        <button
                                            onClick={() => onEdit(submenu)}
                                            className="p-1 text-blue-600 hover:bg-blue-100 rounded"
                                            disabled={loading}
                                            title="Editar submenú"
                                        >
                                            <Icon name="Edit" size={14} />
                                        </button>
                                        <button
                                            onClick={() => onDelete(submenu)}
                                            className="p-1 text-red-600 hover:bg-red-100 rounded"
                                            disabled={loading}
                                            title="Eliminar submenú"
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

SubmenusList.displayName = 'SubmenusList';

// ✅ Hook personalizado para gestión de submenús
const useSubmenuManagement = (showMessage, loadSubmenus) => {
    const [showSubmenuForm, setShowSubmenuForm] = useState(false);
    const [editingSubmenu, setEditingSubmenu] = useState(null);
    const [submenuFormKey, setSubmenuFormKey] = useState(0);

    const handleSubmenuSave = useCallback(async (formData, editingSubmenu) => {
        console.log('💾 Guardando submenú:', formData);

        try {
            const cleanData = {
                sub_nom: formData.sub_nom?.trim(),
                ico_id: formData.ico_id ? parseInt(formData.ico_id) : null,
                sub_componente: formData.sub_componente?.trim() || null,
                sub_eje: parseInt(formData.sub_eje) || 1,
                sub_ventana_directa: Boolean(formData.sub_ventana_directa),
                menu_ids: formData.menu_ids || []
            };

            console.log('📤 Datos limpios de submenú a enviar:', cleanData);

            let result;
            if (editingSubmenu) {
                result = await adminService.submenus.update(editingSubmenu.sub_id, cleanData);
                showMessage('success', 'Submenú actualizado correctamente');
                console.log('✅ Submenú actualizado:', result);
            } else {
                result = await adminService.submenus.create(cleanData);
                showMessage('success', 'Submenú creado correctamente');
                console.log('✅ Submenú creado:', result);
            }

            await loadSubmenus();
            setShowSubmenuForm(false);
            setEditingSubmenu(null);
            setSubmenuFormKey(prev => prev + 1);

        } catch (error) {
            console.error('❌ Error completo submenú:', error);
            console.error('❌ Error response submenú:', error.response?.data);

            let errorMsg = 'Error al guardar el submenú';

            if (error.response?.data?.message) {
                errorMsg = error.response.data.message;
            } else if (error.message) {
                errorMsg = error.message;
            }

            showMessage('error', errorMsg);
        }
    }, [showMessage, loadSubmenus]);

    const handleSubmenuCancel = useCallback(() => {
        console.log('❌ Cancelando formulario de submenú');
        setShowSubmenuForm(false);
        setEditingSubmenu(null);
        setSubmenuFormKey(prev => prev + 1);
    }, []);

    const handleNewSubmenu = useCallback(() => {
        console.log('➕ Nuevo submenú');
        setEditingSubmenu(null);
        setShowSubmenuForm(true);
        setSubmenuFormKey(prev => prev + 1);
    }, []);

    const handleEditSubmenu = useCallback((submenu) => {
        console.log('✏️ Editar submenú:', submenu.sub_id);
        setEditingSubmenu(submenu);
        setShowSubmenuForm(true);
        setSubmenuFormKey(prev => prev + 1);
    }, []);

    const handleDeleteSubmenu = useCallback(async (submenu) => {
        if (window.confirm(`¿Eliminar el submenú "${submenu.sub_nom}"?`)) {
            try {
                await adminService.submenus.delete(submenu.sub_id);
                showMessage('success', 'Submenú eliminado correctamente');
                await loadSubmenus();
            } catch (error) {
                console.error('Error eliminando submenú:', error);
                const errorMsg = error.response?.data?.message || 'Error al eliminar el submenú';
                showMessage('error', errorMsg);
            }
        }
    }, [showMessage, loadSubmenus]);

    return {
        showSubmenuForm,
        editingSubmenu,
        submenuFormKey,
        handleSubmenuSave,
        handleSubmenuCancel,
        handleNewSubmenu,
        handleEditSubmenu,
        handleDeleteSubmenu
    };
};

// ✅ Exportar componentes y hook
export { SubmenuForm, SubmenusList, useSubmenuManagement };