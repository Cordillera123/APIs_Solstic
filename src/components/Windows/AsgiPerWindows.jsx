// src/components/Windows/AsgiPerWindows.jsx - SISTEMA COMPLETO DE PERMISOS
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { adminService } from '../../services/apiService';
import Icon from '../UI/Icon';

const AsgiPerWindows = ({ data }) => {
    // ===== ESTADOS =====
    const [perfiles, setPerfiles] = useState([]);
    const [selectedProfile, setSelectedProfile] = useState(null);
    const [menuStructure, setMenuStructure] = useState([]);
    const [loading, setLoading] = useState(true);
    const [savingPermissions, setSavingPermissions] = useState(false);
    const [message, setMessage] = useState({ type: '', text: '' });
    const [expandedMenus, setExpandedMenus] = useState(new Set());
    const [expandedSubmenus, setExpandedSubmenus] = useState(new Set());

    // ===== FUNCIONES BÁSICAS =====
    const showMessage = useCallback((type, text) => {
        setMessage({ type, text });
        setTimeout(() => setMessage({ type: '', text: '' }), 4000);
    }, []);

    // ===== CARGAR DATOS =====
    const loadProfiles = useCallback(async () => {
        console.log('🔍 Iniciando carga de perfiles...');
        setLoading(true);

        try {
            console.log('📡 Llamando a adminService.permissions.getProfiles()...');
            const result = await adminService.permissions.getProfiles();
            console.log('📥 Respuesta recibida:', result);

            if (result.status === 'success') {
                console.log('✅ Perfiles cargados:', result.perfiles);
                setPerfiles(result.perfiles || []);
            } else {
                console.error('❌ Status no exitoso:', result);
                showMessage('error', 'Error en la respuesta del servidor');
            }
        } catch (error) {
            console.error('💥 Error completo:', error);
            console.error('📄 Error response:', error.response);
            console.error('📝 Error message:', error.message);

            let errorMessage = 'Error al cargar perfiles';
            if (error.response?.data?.message) {
                errorMessage = error.response.data.message;
            } else if (error.response?.status === 401) {
                errorMessage = 'No autorizado - Token inválido';
            } else if (error.response?.status === 404) {
                errorMessage = 'Endpoint no encontrado';
            } else if (error.message) {
                errorMessage = error.message;
            }

            showMessage('error', errorMessage);
        } finally {
            console.log('🏁 Finalizando carga de perfiles');
            setLoading(false);
        }
    }, [showMessage]);

    const loadMenuStructure = useCallback(async (perfilId) => {
        if (!perfilId) return;

        setLoading(true);
        try {
            const result = await adminService.permissions.getMenuStructureWithPermissions(perfilId);
            if (result.status === 'success') {
                setMenuStructure(result.menu_structure || []);
            }
        } catch (error) {
            console.error('Error loading menu structure:', error);
            showMessage('error', 'Error al cargar estructura de menús');
        } finally {
            setLoading(false);
        }
    }, [showMessage]);

    // ===== EFECTOS =====
    useEffect(() => {
        loadProfiles();
    }, [loadProfiles]);

    useEffect(() => {
        if (selectedProfile) {
            loadMenuStructure(selectedProfile.per_id);
        }
    }, [selectedProfile, loadMenuStructure]);

    // ===== MANEJO DE PERMISOS =====
    const togglePermission = useCallback(async (menId, subId = null, opcId = null, currentState) => {
        setSavingPermissions(true);

        try {
            const permissionData = {
                per_id: selectedProfile.per_id,
                men_id: menId,
                sub_id: subId,
                opc_id: opcId,
                grant_permission: !currentState
            };

            const result = await adminService.permissions.togglePermission(permissionData);
            if (result.status === 'success') {
                // Recargar estructura para reflejar cambios
                await loadMenuStructure(selectedProfile.per_id);
                showMessage('success', result.message);
            }
        } catch (error) {
            console.error('Error toggling permission:', error);
            showMessage('error', 'Error al modificar permiso');
        } finally {
            setSavingPermissions(false);
        }
    }, [selectedProfile, loadMenuStructure, showMessage]);

    // ===== MANEJO DE EXPANSIÓN =====
    const toggleMenuExpansion = useCallback((menuId) => {
        setExpandedMenus(prev => {
            const newSet = new Set(prev);
            if (newSet.has(menuId)) {
                newSet.delete(menuId);
            } else {
                newSet.add(menuId);
            }
            return newSet;
        });
    }, []);

    const toggleSubmenuExpansion = useCallback((submenuId) => {
        setExpandedSubmenus(prev => {
            const newSet = new Set(prev);
            if (newSet.has(submenuId)) {
                newSet.delete(submenuId);
            } else {
                newSet.add(submenuId);
            }
            return newSet;
        });
    }, []);

    // ===== COMPONENTES =====
    const ProfileCard = ({ perfil, isSelected, onClick }) => (
        <div
            className={`border rounded-lg p-4 cursor-pointer transition-all ${isSelected
                    ? 'border-blue-500 bg-blue-50 shadow-md'
                    : 'border-gray-200 hover:bg-gray-50 hover:border-gray-300'
                }`}
            onClick={onClick}
        >
            <div className="flex items-center justify-between">
                <div>
                    <div className="flex items-center">
                        <Icon
                            name={perfil.per_nom === 'Super' ? 'Crown' : perfil.per_nom === 'Administrador' ? 'Shield' : 'User'}
                            size={16}
                            className={`mr-2 ${isSelected ? 'text-blue-600' : 'text-gray-500'}`}
                        />
                        <span className={`font-medium ${isSelected ? 'text-blue-900' : 'text-gray-900'}`}>
                            {perfil.per_nom}
                        </span>
                    </div>
                    <p className={`text-sm mt-1 ${isSelected ? 'text-blue-700' : 'text-gray-600'}`}>
                        {perfil.usuarios_count} usuarios
                    </p>
                </div>
                <Icon
                    name="ChevronRight"
                    size={16}
                    className={`transition-colors ${isSelected ? 'text-blue-500' : 'text-gray-400'}`}
                />
            </div>
        </div>
    );

    const PermissionCheckbox = ({ checked, onChange, disabled, label, level = 0 }) => {
        const getCheckboxColor = () => {
            if (level === 0) return 'text-blue-600'; // Menú
            if (level === 1) return 'text-purple-600'; // Submenú
            return 'text-green-600'; // Opción
        };

        return (
            <label className="flex items-center cursor-pointer">
                <input
                    type="checkbox"
                    checked={checked}
                    onChange={onChange}
                    disabled={disabled}
                    className={`mr-2 ${getCheckboxColor()} focus:ring-2 focus:ring-blue-500`}
                />
                <span className={`text-sm ${disabled ? 'text-gray-400' : 'text-gray-700'}`}>
                    {label}
                </span>
            </label>
        );
    };

    const MenuTreeItem = ({ menu }) => {
        const isMenuExpanded = expandedMenus.has(menu.men_id);
        const hasSubmenus = menu.submenus && menu.submenus.length > 0;

        return (
            <div className="mb-3">
                {/* Menú Principal */}
                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg border">
                    <div className="flex items-center flex-1">
                        {hasSubmenus && (
                            <button
                                onClick={() => toggleMenuExpansion(menu.men_id)}
                                className="mr-2 p-1 hover:bg-gray-200 rounded"
                                disabled={savingPermissions}
                            >
                                <Icon
                                    name={isMenuExpanded ? 'ChevronDown' : 'ChevronRight'}
                                    size={14}
                                    className="text-gray-500"
                                />
                            </button>
                        )}

                        <div className="flex items-center flex-1">
                            {menu.ico_nombre && (
                                <Icon name={menu.ico_nombre} size={16} className="mr-2 text-gray-600" />
                            )}
                            <span className="font-medium text-gray-900">{menu.men_nom}</span>
                            {menu.men_componente && (
                                <span className="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 rounded text-xs font-mono">
                                    {menu.men_componente}
                                </span>
                            )}
                        </div>
                    </div>

                    <PermissionCheckbox
                        checked={menu.has_permission}
                        onChange={() => togglePermission(menu.men_id, null, null, menu.has_permission)}
                        disabled={savingPermissions}
                        label="Acceso al menú"
                        level={0}
                    />
                </div>

                {/* Submenús */}
                {hasSubmenus && isMenuExpanded && (
                    <div className="ml-6 mt-2 space-y-2">
                        {menu.submenus.map(submenu => (
                            <SubmenuTreeItem
                                key={submenu.sub_id}
                                submenu={submenu}
                                menuId={menu.men_id}
                            />
                        ))}
                    </div>
                )}
            </div>
        );
    };

    const SubmenuTreeItem = ({ submenu, menuId }) => {
        const isSubmenuExpanded = expandedSubmenus.has(submenu.sub_id);
        const hasOptions = submenu.opciones && submenu.opciones.length > 0;

        return (
            <div className="border-l-2 border-purple-200 pl-4">
                {/* Submenú */}
                <div className="flex items-center justify-between p-2 bg-purple-50 rounded border">
                    <div className="flex items-center flex-1">
                        {hasOptions && (
                            <button
                                onClick={() => toggleSubmenuExpansion(submenu.sub_id)}
                                className="mr-2 p-1 hover:bg-purple-100 rounded"
                                disabled={savingPermissions}
                            >
                                <Icon
                                    name={isSubmenuExpanded ? 'ChevronDown' : 'ChevronRight'}
                                    size={12}
                                    className="text-purple-600"
                                />
                            </button>
                        )}

                        <div className="flex items-center flex-1">
                            {submenu.ico_nombre && (
                                <Icon name={submenu.ico_nombre} size={14} className="mr-2 text-purple-600" />
                            )}
                            <span className="text-sm font-medium text-purple-900">{submenu.sub_nom}</span>
                            {submenu.sub_componente && (
                                <span className="ml-2 px-2 py-0.5 bg-purple-100 text-purple-800 rounded text-xs font-mono">
                                    {submenu.sub_componente}
                                </span>
                            )}
                        </div>
                    </div>

                    <PermissionCheckbox
                        checked={submenu.has_permission}
                        onChange={() => togglePermission(menuId, submenu.sub_id, null, submenu.has_permission)}
                        disabled={savingPermissions}
                        label="Acceso al submenú"
                        level={1}
                    />
                </div>

                {/* Opciones */}
                {hasOptions && isSubmenuExpanded && (
                    <div className="ml-4 mt-2 space-y-1">
                        {submenu.opciones.map(opcion => (
                            <div
                                key={opcion.opc_id}
                                className="flex items-center justify-between p-2 bg-green-50 rounded border border-green-200"
                            >
                                <div className="flex items-center flex-1">
                                    {opcion.ico_nombre && (
                                        <Icon name={opcion.ico_nombre} size={12} className="mr-2 text-green-600" />
                                    )}
                                    <span className="text-sm text-green-900">{opcion.opc_nom}</span>
                                    {opcion.opc_componente && (
                                        <span className="ml-2 px-2 py-0.5 bg-green-100 text-green-800 rounded text-xs font-mono">
                                            {opcion.opc_componente}
                                        </span>
                                    )}
                                </div>

                                <PermissionCheckbox
                                    checked={opcion.has_permission}
                                    onChange={() => togglePermission(menuId, submenu.sub_id, opcion.opc_id, opcion.has_permission)}
                                    disabled={savingPermissions}
                                    label="Acceso a la opción"
                                    level={2}
                                />
                            </div>
                        ))}
                    </div>
                )}
            </div>
        );
    };

    // ===== ACCIONES MASIVAS =====
    const expandAllMenus = useCallback(() => {
        const allMenuIds = new Set(menuStructure.map(menu => menu.men_id));
        const allSubmenuIds = new Set();

        menuStructure.forEach(menu => {
            if (menu.submenus) {
                menu.submenus.forEach(submenu => {
                    allSubmenuIds.add(submenu.sub_id);
                });
            }
        });

        setExpandedMenus(allMenuIds);
        setExpandedSubmenus(allSubmenuIds);
    }, [menuStructure]);

    const collapseAllMenus = useCallback(() => {
        setExpandedMenus(new Set());
        setExpandedSubmenus(new Set());
    }, []);

    const grantAllPermissions = useCallback(async () => {
        if (!selectedProfile || !window.confirm('¿Otorgar TODOS los permisos a este perfil?')) {
            return;
        }

        setSavingPermissions(true);
        try {
            const permissions = [];

            menuStructure.forEach(menu => {
                // Permiso de menú
                if (!menu.has_permission) {
                    permissions.push({
                        men_id: menu.men_id,
                        sub_id: null,
                        opc_id: null,
                        grant: true
                    });
                }

                // Permisos de submenús y opciones
                if (menu.submenus) {
                    menu.submenus.forEach(submenu => {
                        if (!submenu.has_permission) {
                            permissions.push({
                                men_id: menu.men_id,
                                sub_id: submenu.sub_id,
                                opc_id: null,
                                grant: true
                            });
                        }

                        if (submenu.opciones) {
                            submenu.opciones.forEach(opcion => {
                                if (!opcion.has_permission) {
                                    permissions.push({
                                        men_id: menu.men_id,
                                        sub_id: submenu.sub_id,
                                        opc_id: opcion.opc_id,
                                        grant: true
                                    });
                                }
                            });
                        }
                    });
                }
            });

            if (permissions.length > 0) {
                const result = await adminService.permissions.bulkAssignPermissions({
                    per_id: selectedProfile.per_id,
                    permissions
                });

                if (result.status === 'success') {
                    await loadMenuStructure(selectedProfile.per_id);
                    showMessage('success', result.message);
                }
            } else {
                showMessage('info', 'El perfil ya tiene todos los permisos');
            }
        } catch (error) {
            console.error('Error granting all permissions:', error);
            showMessage('error', 'Error al otorgar permisos');
        } finally {
            setSavingPermissions(false);
        }
    }, [selectedProfile, menuStructure, loadMenuStructure, showMessage]);

    const revokeAllPermissions = useCallback(async () => {
        if (!selectedProfile || !window.confirm('¿REVOCAR todos los permisos de este perfil?')) {
            return;
        }

        setSavingPermissions(true);
        try {
            const permissions = [];

            menuStructure.forEach(menu => {
                // Permiso de menú
                if (menu.has_permission) {
                    permissions.push({
                        men_id: menu.men_id,
                        sub_id: null,
                        opc_id: null,
                        grant: false
                    });
                }

                // Permisos de submenús y opciones
                if (menu.submenus) {
                    menu.submenus.forEach(submenu => {
                        if (submenu.has_permission) {
                            permissions.push({
                                men_id: menu.men_id,
                                sub_id: submenu.sub_id,
                                opc_id: null,
                                grant: false
                            });
                        }

                        if (submenu.opciones) {
                            submenu.opciones.forEach(opcion => {
                                if (opcion.has_permission) {
                                    permissions.push({
                                        men_id: menu.men_id,
                                        sub_id: submenu.sub_id,
                                        opc_id: opcion.opc_id,
                                        grant: false
                                    });
                                }
                            });
                        }
                    });
                }
            });

            if (permissions.length > 0) {
                const result = await adminService.permissions.bulkAssignPermissions({
                    per_id: selectedProfile.per_id,
                    permissions
                });

                if (result.status === 'success') {
                    await loadMenuStructure(selectedProfile.per_id);
                    showMessage('success', result.message);
                }
            } else {
                showMessage('info', 'El perfil no tiene permisos para revocar');
            }
        } catch (error) {
            console.error('Error revoking all permissions:', error);
            showMessage('error', 'Error al revocar permisos');
        } finally {
            setSavingPermissions(false);
        }
    }, [selectedProfile, menuStructure, loadMenuStructure, showMessage]);

    // ===== ESTADÍSTICAS =====
    const permissionsStats = useMemo(() => {
        if (!menuStructure.length) return { total: 0, granted: 0, percentage: 0 };

        let total = 0;
        let granted = 0;

        menuStructure.forEach(menu => {
            total++;
            if (menu.has_permission) granted++;

            if (menu.submenus) {
                menu.submenus.forEach(submenu => {
                    total++;
                    if (submenu.has_permission) granted++;

                    if (submenu.opciones) {
                        submenu.opciones.forEach(opcion => {
                            total++;
                            if (opcion.has_permission) granted++;
                        });
                    }
                });
            }
        });

        return {
            total,
            granted,
            percentage: total > 0 ? Math.round((granted / total) * 100) : 0
        };
    }, [menuStructure]);

    // ===== RENDER PRINCIPAL =====
    if (loading && !selectedProfile) {
        return (
            <div className="p-6 h-full flex items-center justify-center">
                <div className="text-center">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-green-600 mx-auto mb-4"></div>
                    <p className="text-gray-600">Cargando perfiles...</p>
                </div>
            </div>
        );
    }

    return (
        <div className="p-6 h-full overflow-auto bg-gray-50">
            {/* Header */}
            <div className="mb-6">
                <h2 className="text-xl font-bold text-gray-800 mb-2">
                    Asignación de Permisos
                </h2>
                <p className="text-gray-600">
                    Configure los permisos de acceso para cada perfil de usuario
                </p>
            </div>

            {/* Mensajes */}
            {message.text && (
                <div className={`mb-4 p-3 rounded-md ${message.type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' :
                        message.type === 'error' ? 'bg-red-50 text-red-800 border border-red-200' :
                            'bg-blue-50 text-blue-800 border border-blue-200'
                    }`}>
                    <div className="flex items-center">
                        <Icon
                            name={message.type === 'success' ? 'CheckCircle' : message.type === 'error' ? 'AlertCircle' : 'Info'}
                            size={16}
                            className="mr-2"
                        />
                        {message.text}
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-4 gap-6 h-full">
                {/* Lista de perfiles */}
                <div className="bg-white rounded-lg border border-gray-200 p-4">
                    <h3 className="font-semibold text-gray-800 mb-4 flex items-center">
                        <Icon name="Users" size={20} className="mr-2" />
                        Perfiles de Usuario
                    </h3>

                    <div className="space-y-3">
                        {perfiles.map((perfil) => (
                            <ProfileCard
                                key={perfil.per_id}
                                perfil={perfil}
                                isSelected={selectedProfile?.per_id === perfil.per_id}
                                onClick={() => setSelectedProfile(perfil)}
                            />
                        ))}
                    </div>

                    {perfiles.length === 0 && (
                        <div className="text-center py-8 text-gray-500">
                            <Icon name="Users" size={48} className="mx-auto mb-4 text-gray-300" />
                            <p>No hay perfiles disponibles</p>
                        </div>
                    )}
                </div>

                {/* Panel de permisos */}
                <div className="lg:col-span-3 bg-white rounded-lg border border-gray-200 p-4 flex flex-col">
                    {selectedProfile ? (
                        <>
                            {/* Header del panel de permisos */}
                            <div className="flex items-center justify-between mb-4 pb-4 border-b">
                                <div>
                                    <h3 className="font-semibold text-gray-800 flex items-center">
                                        <Icon name="Shield" size={20} className="mr-2" />
                                        Permisos: {selectedProfile.per_nom}
                                    </h3>
                                    <div className="flex items-center mt-2 text-sm text-gray-600">
                                        <span>Permisos otorgados: {permissionsStats.granted}/{permissionsStats.total}</span>
                                        <span className="ml-3 px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">
                                            {permissionsStats.percentage}%
                                        </span>
                                    </div>
                                </div>

                                {/* Acciones rápidas */}
                                <div className="flex gap-2">
                                    <button
                                        onClick={expandAllMenus}
                                        className="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 flex items-center"
                                        disabled={savingPermissions}
                                    >
                                        <Icon name="Maximize" size={14} className="mr-1" />
                                        Expandir
                                    </button>
                                    <button
                                        onClick={collapseAllMenus}
                                        className="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 flex items-center"
                                        disabled={savingPermissions}
                                    >
                                        <Icon name="Minimize" size={14} className="mr-1" />
                                        Colapsar
                                    </button>
                                    <button
                                        onClick={grantAllPermissions}
                                        className="px-3 py-1 text-sm bg-green-100 text-green-700 rounded hover:bg-green-200 flex items-center"
                                        disabled={savingPermissions}
                                    >
                                        <Icon name="CheckCircle" size={14} className="mr-1" />
                                        Otorgar Todos
                                    </button>
                                    <button
                                        onClick={revokeAllPermissions}
                                        className="px-3 py-1 text-sm bg-red-100 text-red-700 rounded hover:bg-red-200 flex items-center"
                                        disabled={savingPermissions}
                                    >
                                        <Icon name="XCircle" size={14} className="mr-1" />
                                        Revocar Todos
                                    </button>
                                </div>
                            </div>

                            {/* Árbol de permisos */}
                            <div className="flex-1 overflow-auto">
                                {loading ? (
                                    <div className="flex items-center justify-center py-12">
                                        <div className="text-center">
                                            <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600 mx-auto mb-3"></div>
                                            <p className="text-gray-600 text-sm">Cargando permisos...</p>
                                        </div>
                                    </div>
                                ) : menuStructure.length > 0 ? (
                                    <div className="space-y-3">
                                        {menuStructure.map(menu => (
                                            <MenuTreeItem key={menu.men_id} menu={menu} />
                                        ))}
                                    </div>
                                ) : (
                                    <div className="text-center py-12 text-gray-500">
                                        <Icon name="AlertCircle" size={48} className="mx-auto mb-4 text-gray-300" />
                                        <p>No hay menús disponibles</p>
                                        <p className="text-sm mt-1">Cree menús en la sección de Parametrización</p>
                                    </div>
                                )}
                            </div>

                            {/* Indicador de guardado */}
                            {savingPermissions && (
                                <div className="mt-4 p-3 bg-blue-50 border border-blue-200 rounded flex items-center">
                                    <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600 mr-3"></div>
                                    <span className="text-blue-800 text-sm">Guardando cambios...</span>
                                </div>
                            )}
                        </>
                    ) : (
                        <div className="flex items-center justify-center h-full text-gray-500">
                            <div className="text-center">
                                <Icon name="Lock" size={48} className="mx-auto mb-4 text-gray-300" />
                                <p>Seleccione un perfil para configurar sus permisos</p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default AsgiPerWindows;