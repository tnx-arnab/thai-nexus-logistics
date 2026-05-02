import React, { useState, useEffect, useRef } from 'react';
import { Save, Plus, Trash2, Search, X, CheckCircle2, AlertCircle, Loader2, DollarSign, Package } from 'lucide-react';
import axios from 'axios';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs) {
  return twMerge(clsx(inputs));
}

const ProductSearchSelect = ({ selectedProducts, onChange }) => {
  const [search, setSearch] = useState('');
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const [showDropdown, setShowDropdown] = useState(false);
  const [selectedDetails, setSelectedDetails] = useState([]);
  const dropdownRef = useRef(null);

  useEffect(() => {
    // Fetch details for already selected products if needed (simplified for MVP: just show IDs if no details)
    if (selectedProducts.length > 0 && selectedDetails.length === 0) {
      setSelectedDetails(selectedProducts.map(id => ({ id, name: `Product #${id}` })));
    }
  }, [selectedProducts]);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setShowDropdown(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    const delayDebounceFn = setTimeout(() => {
      if (search) {
        performSearch(search);
      } else {
        setResults([]);
      }
    }, 500);
    return () => clearTimeout(delayDebounceFn);
  }, [search]);

  const performSearch = async (query) => {
    setSearching(true);
    try {
      // @ts-ignore
      const response = await axios.get(`${window.tnxData.apiUrl}/search-products?search=${encodeURIComponent(query)}`, {
        // @ts-ignore
        headers: { 'X-WP-Nonce': window.tnxData.nonce }
      });
      setResults(response.data);
    } catch (error) {
      console.error("Search failed", error);
    } finally {
      setSearching(false);
    }
  };

  const handleSelect = (product) => {
    if (!selectedProducts.includes(product.id)) {
      onChange([...selectedProducts, product.id]);
      setSelectedDetails([...selectedDetails, product]);
    }
    setSearch('');
    setShowDropdown(false);
  };

  const handleRemove = (id) => {
    onChange(selectedProducts.filter(pId => pId !== id));
    setSelectedDetails(selectedDetails.filter(p => p.id !== id));
  };

  return (
    <div className="relative" ref={dropdownRef}>
      <div className="flex flex-wrap gap-2 mb-3">
        {selectedDetails.map(p => (
          <span key={p.id} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary/5 text-primary text-xs font-semibold border border-primary/20 animate-in fade-in zoom-in duration-200">
            {p.name}
            <button 
              type="button" 
              onClick={() => handleRemove(p.id)} 
              className="hover:bg-primary/10 rounded-full p-0.5 transition-colors"
            >
              <X size={12} />
            </button>
          </span>
        ))}
      </div>
      <div className="relative">
        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
          {searching ? <Loader2 size={16} className="text-gray-400 animate-spin" /> : <Search size={16} className="text-gray-400" />}
        </div>
        <input
          type="text"
          className="tnx-input !pl-12"
          placeholder="Search products to add..."
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setShowDropdown(true);
          }}
          onFocus={() => setShowDropdown(true)}
        />
      </div>
      
      {showDropdown && (search || results.length > 0) && (
        <div className="absolute z-[100] mt-2 w-full bg-white shadow-xl rounded-xl border border-gray-100 py-2 max-h-72 overflow-y-auto animate-in fade-in slide-in-from-top-2 duration-200">
          {searching && results.length === 0 ? (
            <div className="px-4 py-8 text-center">
              <Loader2 size={24} className="text-primary animate-spin mx-auto mb-2 opacity-20" />
              <p className="text-sm text-gray-400">Searching products...</p>
            </div>
          ) : results.length === 0 && search ? (
            <div className="px-4 py-8 text-center">
              <Search size={24} className="text-gray-300 mx-auto mb-2" />
              <p className="text-sm text-gray-400">No products found for "{search}"</p>
            </div>
          ) : (
            results.map((product) => (
              <button
                key={product.id}
                type="button"
                className="w-full text-left px-4 py-3 hover:bg-gray-50 flex items-center gap-3 transition-colors border-b border-gray-50 last:border-0"
                onClick={() => handleSelect(product)}
              >
                <div className="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400 shrink-0">
                   <Package size={18} />
                </div>
                <div className="flex flex-col min-w-0">
                  <span className="font-semibold text-gray-900 truncate">{product.name}</span>
                  <div className="flex items-center gap-2">
                    {product.sku && <span className="text-gray-400 text-[10px] uppercase tracking-wider font-bold">SKU: {product.sku}</span>}
                    <span className="text-gray-400 text-[10px] uppercase tracking-wider font-bold">ID: {product.id}</span>
                  </div>
                </div>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
};

const CommissionRulesPage = () => {
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null);
  const [rules, setRules] = useState([]);
  const [currencySymbol, setCurrencySymbol] = useState('฿');

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    setLoading(true);
    try {
      // @ts-ignore
      const response = await axios.get(window.tnxData.apiUrl + '/settings', {
        // @ts-ignore
        headers: { 'X-WP-Nonce': window.tnxData.nonce }
      });
      setRules(response.data.commission_rules || []);
      setCurrencySymbol(response.data.currency_symbol || '฿');
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
      await axios.post(window.tnxData.apiUrl + '/settings', { commission_rules: rules }, {
        // @ts-ignore
        headers: { 'X-WP-Nonce': window.tnxData.nonce }
      });
      setMessage({ type: 'success', text: 'Rules saved successfully!' });
      setTimeout(() => setMessage(null), 3000);
    } catch (error) {
      setMessage({ type: 'error', text: 'Failed to save rules. Please try again.' });
    } finally {
      setSaving(false);
    }
  };

  const addRule = () => {
    setRules([...rules, {
      condition_type: 'subtotal_range',
      min_range: 0,
      max_range: 0,
      specific_products: [],
      fee_type: 'fixed',
      fee_value: 0,
      fee_label: 'Commission Fee'
    }]);
  };

  const removeRule = (index) => {
    const newRules = [...rules];
    newRules.splice(index, 1);
    setRules(newRules);
  };

  const updateRule = (index, field, value) => {
    const newRules = [...rules];
    newRules[index][field] = value;
    setRules(newRules);
  };

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center py-20 bg-white rounded-2xl border border-gray-100 shadow-sm">
        <Loader2 className="w-10 h-10 text-primary animate-spin" />
        <p className="text-gray-500 mt-4 font-medium">Loading rules...</p>
      </div>
    );
  }

  return (
    <form onSubmit={handleSave} className="space-y-6">
      <div className="flex justify-between items-center mb-4">
        <div>
          <h2 className="text-xl font-bold text-gray-900 flex items-center gap-2">
            <DollarSign className="text-primary" />
            Commission Rules
          </h2>
          <p className="text-gray-500 text-sm mt-1">Configure automated fees applied at checkout.</p>
        </div>
        <button
          type="button"
          onClick={addRule}
          className="flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 shadow-sm rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors text-gray-700"
        >
          <Plus size={16} />
          Add Rule
        </button>
      </div>

      {rules.length === 0 ? (
        <div className="bg-white rounded-xl border border-dashed border-gray-300 p-12 text-center">
          <DollarSign className="w-12 h-12 text-gray-300 mx-auto mb-3" />
          <h3 className="text-lg font-medium text-gray-900 mb-1">No commission rules</h3>
          <p className="text-gray-500 mb-4">You haven't set up any dynamic fees yet.</p>
          <button
            type="button"
            onClick={addRule}
            className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-red-700 transition-colors"
          >
            <Plus size={16} />
            Create First Rule
          </button>
        </div>
      ) : (
        <div className="space-y-6">
          {rules.map((rule, index) => (
            <div key={index} className="bg-white rounded-xl shadow-sm border border-gray-200 relative group">
              <div className="bg-gray-50 border-b border-gray-100 p-4 flex justify-between items-center">
                <span className="font-semibold text-gray-700">Rule #{index + 1}</span>
                <button
                  type="button"
                  onClick={() => removeRule(index)}
                  className="text-red-500 hover:text-red-700 p-1.5 rounded-md hover:bg-red-50 transition-colors opacity-0 group-hover:opacity-100 focus:opacity-100"
                  title="Remove Rule"
                >
                  <Trash2 size={16} />
                </button>
              </div>
              <div className="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                
                {/* Condition Side */}
                <div className="space-y-4">
                  <h4 className="text-sm font-bold text-gray-500 uppercase tracking-wider mb-2">Condition</h4>
                  
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Apply based on</label>
                    <select
                      className="tnx-input"
                      value={rule.condition_type}
                      onChange={(e) => updateRule(index, 'condition_type', e.target.value)}
                    >
                      <option value="subtotal_range">Cart Subtotal Range</option>
                      <option value="specific_products">Specific Products in Cart</option>
                    </select>
                  </div>

                  {rule.condition_type === 'subtotal_range' && (
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Min Subtotal</label>
                        <div className="relative">
                          <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">{currencySymbol}</span>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            className="tnx-input !pl-10"
                            value={rule.min_range}
                            onChange={(e) => updateRule(index, 'min_range', e.target.value)}
                          />
                        </div>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Max Subtotal</label>
                        <div className="relative">
                          <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">{currencySymbol}</span>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            className="tnx-input !pl-10"
                            value={rule.max_range}
                            onChange={(e) => updateRule(index, 'max_range', e.target.value)}
                            placeholder="0 for no limit"
                          />
                        </div>
                        <p className="text-xs text-gray-500 mt-1">Set to 0 for unlimited</p>
                      </div>
                    </div>
                  )}

                  {rule.condition_type === 'specific_products' && (
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Select Products</label>
                      <ProductSearchSelect 
                        selectedProducts={rule.specific_products || []}
                        onChange={(products) => updateRule(index, 'specific_products', products)}
                      />
                    </div>
                  )}
                </div>

                {/* Fee Side */}
                <div className="space-y-4 md:border-l md:border-gray-100 md:pl-6">
                  <h4 className="text-sm font-bold text-gray-500 uppercase tracking-wider mb-2">Fee Calculation</h4>
                  
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Fee Label (Customer Facing)</label>
                    <input
                      type="text"
                      className="tnx-input"
                      value={rule.fee_label}
                      onChange={(e) => updateRule(index, 'fee_label', e.target.value)}
                      placeholder="e.g. Handling Fee"
                      required
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Fee Type</label>
                      <select
                        className="tnx-input"
                        value={rule.fee_type}
                        onChange={(e) => updateRule(index, 'fee_type', e.target.value)}
                      >
                        <option value="fixed">Fixed Price</option>
                        <option value="percentage">Percentage (%)</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                      <div className="relative">
                        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                          {rule.fee_type === 'fixed' ? currencySymbol : '%'}
                        </span>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          className="tnx-input !pl-10"
                          value={rule.fee_value}
                          onChange={(e) => updateRule(index, 'fee_value', e.target.value)}
                          required
                        />
                      </div>
                    </div>
                  </div>
                </div>

              </div>
            </div>
          ))}
        </div>
      )}

      {/* Save Button & Feedback */}
      <div className="flex items-center gap-6 pt-4">
        <button
          type="submit"
          disabled={saving}
          className="tnx-btn-primary py-3 px-10 text-lg shadow-lg shadow-red-100 disabled:opacity-50"
        >
          {saving ? <Loader2 className="animate-spin" /> : <Save size={20} />}
          {saving ? 'Saving...' : 'Save Rules'}
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

export default CommissionRulesPage;
