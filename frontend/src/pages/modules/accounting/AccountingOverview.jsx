import { Link } from 'react-router-dom'
import { 
  BookOpen, 
  FileText, 
  TrendingUp, 
  CreditCard, 
  Receipt, 
  PieChart,
  ArrowRight,
  DollarSign,
  AlertCircle
} from 'lucide-react'

export default function AccountingOverview() {
  const features = [
    {
      title: 'Chart of Accounts',
      description: 'Manage your account structure and categories',
      icon: BookOpen,
      href: '/modules/accounting/accounts',
      color: 'bg-blue-50 text-blue-600',
    },
    {
      title: 'Journal Entries',
      description: 'Record and manage financial transactions',
      icon: FileText,
      href: '/modules/accounting/journal',
      color: 'bg-green-50 text-green-600',
    },
    {
      title: 'General Ledger',
      description: 'View account activity and balances',
      icon: TrendingUp,
      href: '/modules/accounting/ledger',
      color: 'bg-purple-50 text-purple-600',
    },
    {
      title: 'Accounts Payable',
      description: 'Track vendor invoices and payments',
      icon: CreditCard,
      href: '/modules/accounting/ap',
      color: 'bg-orange-50 text-orange-600',
    },
    {
      title: 'Accounts Receivable',
      description: 'Manage customer invoices and receipts',
      icon: Receipt,
      href: '/modules/accounting/ar',
      color: 'bg-cyan-50 text-cyan-600',
    },
    {
      title: 'Reports',
      description: 'Generate financial statements and reports',
      icon: PieChart,
      href: '/modules/accounting/reports',
      color: 'bg-pink-50 text-pink-600',
    },
  ]

  return (
    <div className="space-y-6">
      {/* Hero with icon */}
      <div className="bg-gradient-to-r from-emerald-600 to-emerald-700 rounded-xl p-6 text-white relative overflow-hidden">
        <div className="absolute right-4 top-1/2 -translate-y-1/2 opacity-20">
          <img 
            src="./assets/icons/icon-accounting.png" 
            alt=""
            className="h-32 w-32 object-contain"
          />
        </div>
        <div className="relative z-10">
          <div className="flex items-center gap-2 text-emerald-200 text-sm mb-2">
            <DollarSign className="w-4 h-4" />
            Accounting Module
          </div>
          <h1 className="text-2xl font-bold mb-2">Financial Management</h1>
          <p className="text-emerald-100 max-w-xl">
            Complete accounting solution with general ledger, accounts payable, accounts receivable, and financial reporting.
          </p>
          <div className="flex gap-3 mt-4">
            <Link 
              to="/modules/accounting/journal?new=true"
              className="bg-white text-emerald-700 px-4 py-2 rounded-lg font-medium hover:bg-emerald-50 transition-colors"
            >
              New Journal Entry
            </Link>
            <Link 
              to="/modules/accounting/reports"
              className="bg-emerald-500 text-white px-4 py-2 rounded-lg font-medium hover:bg-emerald-400 transition-colors"
            >
              View Reports
            </Link>
          </div>
        </div>
      </div>

      {/* Quick Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        {[
          { label: 'Total Assets', value: '—', change: '' },
          { label: 'Total Liabilities', value: '—', change: '' },
          { label: 'Net Income (MTD)', value: '—', change: '' },
          { label: 'Cash Balance', value: '—', change: '' },
        ].map((stat, i) => (
          <div key={i} className="bg-white rounded-xl border p-4">
            <div className="text-sm text-gray-500">{stat.label}</div>
            <div className="text-2xl font-bold text-gray-900 mt-1">{stat.value}</div>
            {stat.change && <div className="text-sm text-green-600 mt-1">{stat.change}</div>}
          </div>
        ))}
      </div>

      {/* Feature Cards */}
      <div>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Quick Access</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {features.map((feature) => (
            <Link
              key={feature.href}
              to={feature.href}
              className="bg-white rounded-xl border p-5 hover:shadow-md hover:border-green-300 transition-all group"
            >
              <div className="flex items-start justify-between">
                <div className={`w-10 h-10 rounded-lg ${feature.color} flex items-center justify-center`}>
                  <feature.icon className="w-5 h-5" />
                </div>
                <ArrowRight className="w-5 h-5 text-gray-400 group-hover:text-green-600 transition-colors" />
              </div>
              <h3 className="font-semibold text-gray-900 mt-3">{feature.title}</h3>
              <p className="text-sm text-gray-500 mt-1">{feature.description}</p>
            </Link>
          ))}
        </div>
      </div>

      {/* Pending Items */}
      <div className="bg-white rounded-xl border p-6">
        <div className="flex items-center gap-2 mb-4">
          <AlertCircle className="w-5 h-5 text-amber-500" />
          <h2 className="text-lg font-semibold text-gray-900">Pending Items</h2>
        </div>
        <div className="text-gray-500 text-sm">
          No pending items at this time.
        </div>
      </div>
    </div>
  )
}
