import React from 'react';
import { Link } from 'react-router-dom';
import { Section, Card } from '../components/UIComponents';
import { Bell, Moon, Globe, Lock, Mail, ChevronRight } from 'lucide-react';

const SettingsPage = ({ session }) => {
  return (
    <>
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>Settings</h1>
        <p style={{ color: 'var(--cf-text-secondary)' }}>Configure your preferences.</p>
      </div>

      <div style={{ display: 'grid', gap: 'var(--cf-space-5)', maxWidth: '600px' }}>
        {/* Email delivery — tenant self-service mail settings */}
        <Link to="/settings/mail" style={{ textDecoration: 'none', color: 'inherit' }} data-testid="settings-mail-link">
          <Card>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
                <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-blue-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-blue)' }}>
                  <Mail size={20} />
                </div>
                <div>
                  <div style={{ fontWeight: 500 }}>Email delivery</div>
                  <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Reply-To address and sender display name for outgoing emails</div>
                </div>
              </div>
              <ChevronRight size={18} style={{ color: 'var(--cf-text-secondary)' }} />
            </div>
          </Card>
        </Link>

        {/* Notifications */}
        <Card>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
              <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-blue-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-blue)' }}>
                <Bell size={20} />
              </div>
              <div>
                <div style={{ fontWeight: 500 }}>Email Notifications</div>
                <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Receive email updates about your account</div>
              </div>
            </div>
            <label style={{ position: 'relative', display: 'inline-block', width: '50px', height: '28px' }}>
              <input type="checkbox" defaultChecked style={{ opacity: 0, width: 0, height: 0 }} />
              <span style={{
                position: 'absolute',
                cursor: 'pointer',
                top: 0, left: 0, right: 0, bottom: 0,
                background: 'var(--cf-accent)',
                borderRadius: '28px',
                transition: '0.3s'
              }}>
                <span style={{
                  position: 'absolute',
                  height: '22px',
                  width: '22px',
                  left: '24px',
                  bottom: '3px',
                  background: 'white',
                  borderRadius: '50%',
                  transition: '0.3s'
                }}></span>
              </span>
            </label>
          </div>
        </Card>

        {/* Dark Mode */}
        <Card>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
              <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-purple-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-purple)' }}>
                <Moon size={20} />
              </div>
              <div>
                <div style={{ fontWeight: 500 }}>Dark Mode</div>
                <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Switch to dark theme</div>
              </div>
            </div>
            <label style={{ position: 'relative', display: 'inline-block', width: '50px', height: '28px' }}>
              <input type="checkbox" style={{ opacity: 0, width: 0, height: 0 }} />
              <span style={{
                position: 'absolute',
                cursor: 'pointer',
                top: 0, left: 0, right: 0, bottom: 0,
                background: 'var(--cf-border)',
                borderRadius: '28px',
                transition: '0.3s'
              }}>
                <span style={{
                  position: 'absolute',
                  height: '22px',
                  width: '22px',
                  left: '3px',
                  bottom: '3px',
                  background: 'white',
                  borderRadius: '50%',
                  transition: '0.3s'
                }}></span>
              </span>
            </label>
          </div>
        </Card>

        {/* Language */}
        <Card>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
              <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-green-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-green)' }}>
                <Globe size={20} />
              </div>
              <div>
                <div style={{ fontWeight: 500 }}>Language</div>
                <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Select your preferred language</div>
              </div>
            </div>
            <select style={{ padding: '8px 12px', borderRadius: '6px', border: '1px solid var(--cf-border)', background: 'white', fontSize: 'var(--cf-text-sm)' }}>
              <option>English</option>
              <option>Spanish</option>
              <option>French</option>
            </select>
          </div>
        </Card>

        {/* Password */}
        <Card>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-4)' }}>
              <div style={{ width: '40px', height: '40px', borderRadius: '8px', background: 'var(--cf-orange-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cf-orange)' }}>
                <Lock size={20} />
              </div>
              <div>
                <div style={{ fontWeight: 500 }}>Change Password</div>
                <div style={{ fontSize: 'var(--cf-text-sm)', color: 'var(--cf-text-secondary)' }}>Update your account password</div>
              </div>
            </div>
            <button style={{ padding: '8px 16px', background: 'var(--cf-primary)', color: 'white', border: 'none', borderRadius: '6px', fontSize: 'var(--cf-text-sm)', cursor: 'pointer' }}>
              Change
            </button>
          </div>
        </Card>
      </div>
    </>
  );
};

export default SettingsPage;
