import React, { useState, useEffect } from 'react';
import { Settings, Package, LayoutDashboard, ExternalLink, DollarSign, Terminal, Shield, Box } from 'lucide-react';
import SettingsPage from './pages/SettingsPage';
import ShipmentsPage from './pages/ShipmentsPage';
import FeesPage from './pages/FeesPage';
import BoxDefinitionsPage from './pages/BoxDefinitionsPage';
import DebugLogPage from './pages/DebugLogPage';
import PrivacyPage from './pages/PrivacyPage';
import ProductsPage from './pages/ProductsPage';
import { fetchSettings } from './lib/api';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs) {
  return twMerge(clsx(inputs));
}

const App = () => {
  const debugEnabled = window.tnxlData?.debugEnabled || false;
  const [activeTab, setActiveTab] = useState('settings');
  const [chargeActualWeightOnly, setChargeActualWeightOnly] = useState(false);

  useEffect(() => {
    void fetchSettings()
      .then((data) => setChargeActualWeightOnly(Boolean(data?.charge_actual_weight_only)))
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (chargeActualWeightOnly && activeTab === 'boxes') {
      setActiveTab('settings');
    }
  }, [chargeActualWeightOnly, activeTab]);

  const tabs = [
    { id: 'shipments', label: 'Shipments', icon: LayoutDashboard },
    { id: 'settings', label: 'Settings', icon: Settings },
    { id: 'products', label: 'Products', icon: Box },
    { id: 'fees', label: 'Fees', icon: DollarSign },
    ...(!chargeActualWeightOnly ? [{ id: 'boxes', label: 'Boxes', icon: Package }] : []),
    { id: 'privacy', label: 'Privacy', icon: Shield },
    ...(debugEnabled ? [{ id: 'debug', label: 'Debug', icon: Terminal }] : []),
  ];

  return (
    <div className="min-h-screen p-6 md:p-10 max-w-7xl mx-auto font-sans">
      <header className="flex flex-col md:flex-row md:items-center justify-between mb-10 gap-4">
        <div>
          <h1 className="text-3xl font-bold text-primary flex items-center gap-3">
            <Package className="text-secondary w-8 h-8" />
            Thai Nexus Logistics
          </h1>
          <p className="text-gray-500 mt-1">Manage your shipping operations and API configurations.</p>
        </div>

        <div className="flex items-center gap-2 bg-white p-1 rounded-xl shadow-sm border border-gray-100 flex-wrap">
          {tabs.map(({ id, label, icon: Icon }) => (
            <button
              key={id}
              type="button"
              onClick={() => setActiveTab(id)}
              className={cn(
                'flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium transition-all text-sm',
                activeTab === id
                  ? id === 'debug'
                    ? 'bg-amber-500 text-white shadow-md'
                    : 'bg-primary text-white shadow-md'
                  : id === 'debug'
                    ? 'text-amber-600 hover:bg-amber-50'
                    : 'text-gray-600 hover:bg-gray-50'
              )}
            >
              <Icon size={16} />
              {label}
            </button>
          ))}
        </div>
      </header>

      <main className="animate-in fade-in slide-in-from-bottom-4 duration-500">
        {activeTab === 'shipments' && <ShipmentsPage />}
        {activeTab === 'settings' && (
          <SettingsPage onSaved={(data) => setChargeActualWeightOnly(Boolean(data?.charge_actual_weight_only))} />
        )}
        {activeTab === 'products' && <ProductsPage />}
        {activeTab === 'fees' && <FeesPage />}
        {activeTab === 'boxes' && !chargeActualWeightOnly && <BoxDefinitionsPage />}
        {activeTab === 'privacy' && <PrivacyPage />}
        {activeTab === 'debug' && debugEnabled && <DebugLogPage />}
      </main>

      <footer className="mt-12 py-6 border-t border-gray-200 flex justify-between items-center text-sm text-gray-500">
        <p>&copy; 2026 Thai Nexus Point Co., Ltd. All rights reserved.</p>
        <a
          href="https://app.thainexus.co.th/"
          target="_blank"
          rel="noopener noreferrer"
          className="flex items-center gap-1 hover:text-primary transition-colors"
        >
          TNXL Portal <ExternalLink size={14} />
        </a>
      </footer>
    </div>
  );
};

export default App;
