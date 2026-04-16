import React from 'react';
import { Section, Card } from '../components/UIComponents';
import { User, Mail, Shield, Building2 } from 'lucide-react';

const ProfilePage = ({ session }) => {
  const { user, tenant } = session;

  return (
    <>
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>Profile</h1>
        <p style={{ color: 'var(--cf-text-secondary)' }}>Manage your account information.</p>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: 'var(--cf-space-6)' }}>
        {/* Avatar Card */}
        <Card>
          <div style={{ textAlign: 'center', padding: 'var(--cf-space-6)' }}>
            <div style={{
              width: '100px',
              height: '100px',
              borderRadius: '50%',
              background: 'var(--cf-accent)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: '36px',
              fontWeight: 700,
              color: 'white',
              margin: '0 auto var(--cf-space-4)'
            }}>
              {(user?.first_name || 'U').charAt(0).toUpperCase()}
            </div>
            <h2 style={{ fontSize: 'var(--cf-text-xl)', fontWeight: 600, marginBottom: 'var(--cf-space-1)' }}>
              {user?.first_name} {user?.last_name}
            </h2>
            <p style={{ color: 'var(--cf-text-secondary)', fontSize: 'var(--cf-text-sm)' }}>{user?.email}</p>
            <div style={{ marginTop: 'var(--cf-space-4)' }}>
              <span style={{ 
                padding: '4px 12px', 
                background: 'var(--cf-accent-light)', 
                color: 'var(--cf-accent)', 
                borderRadius: '20px', 
                fontSize: '12px', 
                fontWeight: 500 
              }}>
                {user?.global_role || user?.role || 'User'}
              </span>
            </div>
          </div>
        </Card>

        {/* Info Card */}
        <Card title="Account Information">
          <div style={{ display: 'grid', gap: 'var(--cf-space-5)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
              <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-purple-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-purple)' }}>
                <User size={20} />
              </div>
              <div>
                <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Full Name</div>
                <div style={{ fontWeight: 500 }}>{user?.first_name} {user?.last_name}</div>
              </div>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
              <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-blue-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-blue)' }}>
                <Mail size={20} />
              </div>
              <div>
                <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Email</div>
                <div style={{ fontWeight: 500 }}>{user?.email}</div>
              </div>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
              <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-green-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-green)' }}>
                <Shield size={20} />
              </div>
              <div>
                <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Role</div>
                <div style={{ fontWeight: 500 }}>{user?.global_role || user?.role || 'User'}</div>
              </div>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
              <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-orange-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-orange)' }}>
                <Building2 size={20} />
              </div>
              <div>
                <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Organization</div>
                <div style={{ fontWeight: 500 }}>{tenant || 'N/A'}</div>
              </div>
            </div>
          </div>
        </Card>
      </div>
    </>
  );
};

export default ProfilePage;
