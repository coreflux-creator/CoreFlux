import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { Plus, Pencil, Trash2, User, Search, Loader2, Shield } from 'lucide-react'

export default function UsersPage() {
  const [users, setUsers] = useState([])
  const [tenants, setTenants] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editingUser, setEditingUser] = useState(null)
  const [formData, setFormData] = useState({ 
    name: '', 
    first_name: '',
    last_name: '',
    email: '', 
    password: '',
    role: 'user',
    tenant_ids: []
  })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    fetchData()
  }, [])

  const fetchData = async () => {
    try {
      const [usersRes, tenantsRes] = await Promise.all([
        api.get('/api/admin/users'),
        api.get('/api/admin/tenants'),
      ])
      setUsers(usersRes.data || [])
      setTenants(tenantsRes.data || [])
    } catch (error) {
      console.error('Failed to fetch data:', error)
    } finally {
      setLoading(false)
    }
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setSaving(true)
    try {
      const payload = { ...formData }
      if (editingUser && !payload.password) {
        delete payload.password
      }
      
      if (editingUser) {
        await api.put(`/api/admin/users/${editingUser.id}`, payload)
      } else {
        await api.post('/api/admin/users', payload)
      }
      fetchData()
      closeModal()
    } catch (error) {
      alert(error.response?.data?.message || 'Failed to save user')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (user) => {
    if (!confirm(`Delete user "${user.email}"? This cannot be undone.`)) return
    try {
      await api.delete(`/api/admin/users/${user.id}`)
      fetchData()
    } catch (error) {
      alert(error.response?.data?.message || 'Failed to delete user')
    }
  }

  const openModal = (user = null) => {
    setEditingUser(user)
    setFormData(user ? { 
      name: user.name || '',
      first_name: user.first_name || '',
      last_name: user.last_name || '',
      email: user.email || '', 
      password: '',
      role: user.role || 'user',
      tenant_ids: user.tenant_ids || []
    } : { 
      name: '', 
      first_name: '',
      last_name: '',
      email: '', 
      password: '',
      role: 'user',
      tenant_ids: []
    })
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    setEditingUser(null)
  }

  const filteredUsers = users.filter(u => 
    u.email?.toLowerCase().includes(search.toLowerCase()) ||
    u.name?.toLowerCase().includes(search.toLowerCase()) ||
    u.first_name?.toLowerCase().includes(search.toLowerCase())
  )

  const roleColors = {
    master_admin: 'bg-red-100 text-red-700',
    admin: 'bg-blue-100 text-blue-700',
    user: 'bg-gray-100 text-gray-700',
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Users</h1>
          <p className="text-gray-500">Manage platform users and their access</p>
        </div>
        <button
          onClick={() => openModal()}
          className="flex items-center gap-2 bg-cf-navy hover:bg-cf-navy-dark text-white px-4 py-2 rounded-lg transition-colors"
        >
          <Plus className="w-4 h-4" />
          Add User
        </button>
      </div>

      {/* Search */}
      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <input
          type="text"
          placeholder="Search users..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-accent focus:border-cf-accent"
        />
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border overflow-hidden">
        <table className="w-full">
          <thead className="bg-gray-50 border-b">
            <tr>
              <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
              <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
              <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
              <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {loading ? (
              <tr>
                <td colSpan="4" className="px-6 py-8 text-center text-gray-500">
                  <Loader2 className="w-6 h-6 animate-spin mx-auto" />
                </td>
              </tr>
            ) : filteredUsers.length === 0 ? (
              <tr>
                <td colSpan="4" className="px-6 py-8 text-center text-gray-500">
                  No users found
                </td>
              </tr>
            ) : (
              filteredUsers.map((user) => (
                <tr key={user.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
                        {user.role === 'master_admin' ? (
                          <Shield className="w-4 h-4 text-red-600" />
                        ) : (
                          <User className="w-4 h-4 text-gray-500" />
                        )}
                      </div>
                      <span className="font-medium text-gray-900">
                        {user.first_name || user.name || 'Unnamed'} {user.last_name || ''}
                      </span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-gray-500">{user.email}</td>
                  <td className="px-6 py-4">
                    <span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${roleColors[user.role] || roleColors.user}`}>
                      {user.role}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button
                      onClick={() => openModal(user)}
                      className="text-gray-400 hover:text-cf-accent p-1"
                    >
                      <Pencil className="w-4 h-4" />
                    </button>
                    <button
                      onClick={() => handleDelete(user)}
                      className="text-gray-400 hover:text-red-600 p-1 ml-2"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md p-6 max-h-[90vh] overflow-y-auto">
            <h2 className="text-xl font-semibold text-gray-900 mb-4">
              {editingUser ? 'Edit User' : 'Add User'}
            </h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                  <input
                    type="text"
                    value={formData.first_name}
                    onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-accent focus:border-cf-accent"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                  <input
                    type="text"
                    value={formData.last_name}
                    onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-accent focus:border-cf-accent"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-accent focus:border-cf-accent"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Password {editingUser && '(leave blank to keep current)'}
                </label>
                <input
                  type="password"
                  value={formData.password}
                  onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-accent focus:border-cf-accent"
                  {...(!editingUser ? { required: true } : {})}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select
                  value={formData.role}
                  onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-accent focus:border-cf-accent"
                >
                  <option value="user">User</option>
                  <option value="admin">Admin</option>
                  <option value="master_admin">Master Admin</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Tenant Access</label>
                <div className="space-y-2 max-h-40 overflow-y-auto border rounded-lg p-2">
                  {tenants.map((tenant) => (
                    <label key={tenant.id} className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={formData.tenant_ids?.includes(tenant.id)}
                        onChange={(e) => {
                          const newIds = e.target.checked
                            ? [...(formData.tenant_ids || []), tenant.id]
                            : (formData.tenant_ids || []).filter(id => id !== tenant.id)
                          setFormData({ ...formData, tenant_ids: newIds })
                        }}
                        className="rounded border-gray-300 text-cf-accent focus:ring-cf-accent"
                      />
                      <span className="text-sm">{tenant.name}</span>
                    </label>
                  ))}
                </div>
              </div>
              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={closeModal}
                  className="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-4 py-2 bg-cf-navy hover:bg-cf-navy-dark text-white rounded-lg transition-colors flex items-center gap-2"
                >
                  {saving && <Loader2 className="w-4 h-4 animate-spin" />}
                  {editingUser ? 'Save Changes' : 'Create User'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
