import React, { useState, useEffect } from 'react';
import { Settings, Package, LayoutDashboard, Save, ExternalLink, RefreshCw, DollarSign, Terminal } from 'lucide-react';
import SettingsPage from './pages/SettingsPage';
import ShipmentsPage from './pages/ShipmentsPage';
import CommissionRulesPage from './pages/CommissionRulesPage';
import BoxDefinitionsPage from './pages/BoxDefinitionsPage';
import DebugLogPage from './pages/DebugLogPage';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs) {
  return twMerge(clsx(inputs));
}

const App = () => {
  // @ts-ignore
  const debugEnabled = window.tnxlData?.debugEnabled || false;
  const [activeTab, setActiveTab] = useState('shipments');
  const [loading, setLoading] = useState(false);

  return (
    <div className="min-h-screen p-6 md:p-10 max-w-7xl mx-auto font-sans">
      {/* Header */}
      <header className="flex flex-col md:flex-row md:items-center justify-between mb-10 gap-4">
        <div>
          <h1 className="text-3xl font-bold text-secondary flex items-center gap-3">
            <Package className="text-primary w-8 h-8" />
            Thai Nexus Logistics
          </h1>
          <p className="text-gray-500 mt-1">Manage your shipping operations and API configurations.</p>
        </div>
        
        <div className="flex items-center gap-4 bg-white p-1 rounded-xl shadow-sm border border-gray-100 flex-wrap">
          <button
            onClick={() => setActiveTab('shipments')}
            className={cn(
              "flex items-center gap-2 px-5 py-2.5 rounded-lg font-medium transition-all",
              activeTab === 'shipments' 
                ? "bg-secondary text-white shadow-md" 
                : "text-gray-600 hover:bg-gray-50"
            )}
          >
            <LayoutDashboard size={18} />
            Shipments
          </button>
          <button
            onClick={() => setActiveTab('settings')}
            className={cn(
              "flex items-center gap-2 px-5 py-2.5 rounded-lg font-medium transition-all",
              activeTab === 'settings' 
                ? "bg-secondary text-white shadow-md" 
                : "text-gray-600 hover:bg-gray-50"
            )}
          >
            <Settings size={18} />
            Settings
          </button>
          <button
            onClick={() => setActiveTab('commission')}
            className={cn(
              "flex items-center gap-2 px-5 py-2.5 rounded-lg font-medium transition-all",
              activeTab === 'commission' 
                ? "bg-secondary text-white shadow-md" 
                : "text-gray-600 hover:bg-gray-50"
            )}
          >
            <DollarSign size={18} />
            Fees
          </button>
          <button
            onClick={() => setActiveTab('boxes')}
            className={cn(
              "flex items-center gap-2 px-5 py-2.5 rounded-lg font-medium transition-all",
              activeTab === 'boxes' 
                ? "bg-secondary text-white shadow-md" 
                : "text-gray-600 hover:bg-gray-50"
            )}
          >
            <Package size={18} />
            Boxes
          </button>
          
          {debugEnabled && (
            <button
              onClick={() => setActiveTab('debug')}
              className={cn(
                "flex items-center gap-2 px-5 py-2.5 rounded-lg font-medium transition-all border border-transparent",
                activeTab === 'debug' 
                  ? "bg-amber-500 text-white shadow-md border-amber-600" 
                  : "text-amber-600 hover:bg-amber-50 border-amber-100/50"
              )}
            >
              <Terminal size={18} />
              Debug
            </button>
          )}
        </div>
      </header>

      {/* Main Content */}
      <main className="animate-in fade-in slide-in-from-bottom-4 duration-500">
        {activeTab === 'shipments' && <ShipmentsPage />}
        {activeTab === 'settings' && <SettingsPage />}
        {activeTab === 'commission' && <CommissionRulesPage />}
        {activeTab === 'boxes' && <BoxDefinitionsPage />}
        {activeTab === 'debug' && debugEnabled && <DebugLogPage />}
      </main>

      {/* Footer */}
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
