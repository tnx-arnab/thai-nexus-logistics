import React, { useState, useEffect } from 'react';
import { Save, Key, MapPin, Phone, User, CheckCircle2, AlertCircle, Loader2 } from 'lucide-react';
import axios from 'axios';

const SettingsPage = () => {
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null);
  const [settings, setSettings] = useState({
    api_token: '',
    shipper: {
      name: '',
      phone: '',
      address: '',
      city: '',
      state: '',
      postal_code: '',
      country: 'TH',
    },
  });

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    setLoading(true);
    try {
      // @ts-ignore
      const response = await axios.get(window.tnxData.apiUrl + '/settings', {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxData.nonce
        }
      });
      setSettings(response.data);
    } catch (error) {
      console.error('Failed to fetch settings', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    try {
      // @ts-ignore
      await axios.post(window.tnxData.apiUrl + '/settings', settings, {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxData.nonce
        }
      });
      setMessage({ type: 'success', text: 'Settings saved successfully!' });
      setTimeout(() => setMessage(null), 3000);
    } catch (error) {
      setMessage({ type: 'error', text: 'Failed to save settings. Please try again.' });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center py-20 bg-white rounded-2xl border border-gray-100 shadow-sm">
        <Loader2 className="w-10 h-10 text-primary animate-spin" />
        <p className="text-gray-500 mt-4 font-medium">Loading your settings...</p>
      </div>
    );
  }

  return (
    <form onSubmit={handleSave} className="space-y-8">
      {/* API Authentication */}
      <div className="tnx-card">
        <div className="bg-secondary p-5 flex items-center gap-3">
          <Key className="text-primary w-6 h-6" />
          <h2 className="text-lg font-bold text-white">API Authentication</h2>
        </div>
        <div className="p-8">
          <div className="max-w-2xl">
            <label className="block text-sm font-semibold text-gray-700 mb-2">User Token</label>
            <input
              type="password"
              className="tnx-input font-mono"
              placeholder="Enter your TNX API Token"
              value={settings.api_token}
              onChange={(e) => setSettings({ ...settings, api_token: e.target.value })}
              required
            />
            <p className="mt-3 text-sm text-gray-500 flex items-center gap-1.5">
              <AlertCircle size={14} className="text-primary" />
              Found in: <a href="https://app.thainexus.co.th/" target="_blank" className="text-primary hover:underline font-medium">Profile Settings &gt; API Token</a>
            </p>
          </div>
          
        </div>
      </div>

      {/* Shipper Address */}
      <div className="tnx-card">
        <div className="bg-secondary p-5 flex items-center gap-3">
          <MapPin className="text-primary w-6 h-6" />
          <h2 className="text-lg font-bold text-white">Store Origin Address</h2>
        </div>
        <div className="p-8">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <User size={16} className="text-gray-400" /> Shipper Name
              </label>
              <input
                type="text"
                className="tnx-input"
                value={settings.shipper.name}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, name: e.target.value } })}
                required
              />
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <Phone size={16} className="text-gray-400" /> Phone Number
              </label>
              <input
                type="text"
                className="tnx-input"
                value={settings.shipper.phone}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, phone: e.target.value } })}
                required
              />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-semibold text-gray-700 mb-2">Address</label>
              <textarea
                className="tnx-input h-24 resize-none"
                value={settings.shipper.address}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, address: e.target.value } })}
                required
              ></textarea>
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2">City</label>
              <input
                type="text"
                className="tnx-input"
                value={settings.shipper.city}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, city: e.target.value } })}
                required
              />
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2">State / Province</label>
              <input
                type="text"
                className="tnx-input"
                value={settings.shipper.state || ''}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, state: e.target.value } })}
              />
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2">Postal Code</label>
              <input
                type="text"
                className="tnx-input"
                value={settings.shipper.postal_code || ''}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, postal_code: e.target.value } })}
              />
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2">Country</label>
              <select
                className="tnx-input bg-white"
                value={settings.shipper.country}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, country: e.target.value } })}
              >
                <option value="TH">Thailand</option>
                {/* Add more countries if needed */}
              </select>
            </div>
          </div>
        </div>
      </div>

      {/* Save Button & Feedback */}
      <div className="flex items-center gap-6">
        <button
          type="submit"
          disabled={saving}
          className="tnx-btn-primary py-3 px-10 text-lg shadow-lg shadow-red-100 disabled:opacity-50"
        >
          {saving ? <Loader2 className="animate-spin" /> : <Save size={20} />}
          {saving ? 'Saving...' : 'Save Settings'}
        </button>

        {message && (
          <div className={`flex items-center gap-2 font-medium px-4 py-2 rounded-lg animate-in fade-in zoom-in duration-300 ${
            message.type === 'success' ? 'text-green-600 bg-green-50' : 'text-red-600 bg-red-50'
          }`}>
            {message.type === 'success' ? <CheckCircle2 size={18} /> : <AlertCircle size={18} />}
            {message.text}
          </div>
        )}
      </div>
    </form>
  );
};

export default SettingsPage;
