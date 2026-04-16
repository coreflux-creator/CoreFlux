import { Link } from 'react-router-dom'
import { 
  Users, 
  Clock, 
  UserCheck, 
  Briefcase, 
  PieChart,
  ArrowRight,
  UserPlus,
  Calendar
} from 'lucide-react'

export default function PeopleOverview() {
  const features = [
    {
      title: 'Employee Directory',
      description: 'View and manage all employees',
      icon: Users,
      href: '/modules/people/directory',
      color: 'bg-blue-50 text-blue-600',
    },
    {
      title: 'Timesheets',
      description: 'Track and submit time entries',
      icon: Clock,
      href: '/modules/people/timesheets',
      color: 'bg-green-50 text-green-600',
    },
    {
      title: 'Approvals',
      description: 'Review pending time and expense approvals',
      icon: UserCheck,
      href: '/modules/people/approvals',
      color: 'bg-amber-50 text-amber-600',
    },
    {
      title: 'Hiring Pipeline',
      description: 'Manage candidates and open positions',
      icon: Briefcase,
      href: '/modules/people/hiring',
      color: 'bg-purple-50 text-purple-600',
    },
    {
      title: 'Reports',
      description: 'Generate HR and workforce reports',
      icon: PieChart,
      href: '/modules/people/reports',
      color: 'bg-pink-50 text-pink-600',
    },
  ]

  return (
    <div className="space-y-6">
      {/* Hero with icon */}
      <div className="bg-gradient-to-r from-violet-600 to-violet-700 rounded-xl p-6 text-white relative overflow-hidden">
        <div className="absolute right-4 top-1/2 -translate-y-1/2 opacity-20">
          <img 
            src="./assets/icons/icon-people.png" 
            alt=""
            className="h-32 w-32 object-contain"
          />
        </div>
        <div className="relative z-10">
          <div className="flex items-center gap-2 text-violet-200 text-sm mb-2">
            <Users className="w-4 h-4" />
            People Module
          </div>
          <h1 className="text-2xl font-bold mb-2">People Management</h1>
          <p className="text-violet-100 max-w-xl">
            Manage your workforce with employee directory, timesheets, approvals, and HR reporting.
          </p>
          <div className="flex gap-3 mt-4">
            <Link 
              to="/modules/people/directory?new=true"
              className="bg-white text-violet-700 px-4 py-2 rounded-lg font-medium hover:bg-violet-50 transition-colors flex items-center gap-2"
            >
              <UserPlus className="w-4 h-4" />
              Add Employee
            </Link>
            <Link 
              to="/modules/people/timesheets"
              className="bg-violet-500 text-white px-4 py-2 rounded-lg font-medium hover:bg-violet-400 transition-colors flex items-center gap-2"
            >
              <Calendar className="w-4 h-4" />
              Submit Timesheet
            </Link>
          </div>
        </div>
      </div>

      {/* Quick Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        {[
          { label: 'Total Employees', value: '—' },
          { label: 'Active Today', value: '—' },
          { label: 'Pending Approvals', value: '—' },
          { label: 'Open Positions', value: '—' },
        ].map((stat, i) => (
          <div key={i} className="bg-white rounded-xl border p-4">
            <div className="text-sm text-gray-500">{stat.label}</div>
            <div className="text-2xl font-bold text-gray-900 mt-1">{stat.value}</div>
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
              className="bg-white rounded-xl border p-5 hover:shadow-md hover:border-blue-300 transition-all group"
            >
              <div className="flex items-start justify-between">
                <div className={`w-10 h-10 rounded-lg ${feature.color} flex items-center justify-center`}>
                  <feature.icon className="w-5 h-5" />
                </div>
                <ArrowRight className="w-5 h-5 text-gray-400 group-hover:text-blue-600 transition-colors" />
              </div>
              <h3 className="font-semibold text-gray-900 mt-3">{feature.title}</h3>
              <p className="text-sm text-gray-500 mt-1">{feature.description}</p>
            </Link>
          ))}
        </div>
      </div>

      {/* Recent Activity */}
      <div className="bg-white rounded-xl border p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Recent Activity</h2>
        <div className="text-gray-500 text-sm">
          No recent activity to display.
        </div>
      </div>
    </div>
  )
}
