import { useCallback, useState, useEffect } from 'react';
import { Save, Key, MapPin, Phone, User, CheckCircle2, AlertCircle, Loader2, ToggleLeft, ToggleRight, Zap, Package, Truck, Wifi, Info } from 'lucide-react';
import axios from 'axios';
import ProductSearchSelect from '../components/ProductSearchSelect';
import CountryMultiSelect from '../components/CountryMultiSelect';

const normalizeServiceId = (value) => String(value || '')
  .trim()
  .toLowerCase()
  .replace(/\s+/g, '_')
  .replace(/[^a-z0-9_-]/g, '');

const serviceKeys = (service) => {
  const seen = new Set();
  const keys = [];
  for (const raw of [service.id, service.service_name, service.name]) {
    const id = normalizeServiceId(raw);
    if (!id || seen.has(id)) continue;
    seen.add(id);
    keys.push(id);
  }
  return keys;
};

const SettingsPage = ({ onSaved }) => {
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null);
  const [settings, setSettings] = useState({
    api_token: '',
    features: {
      checkout_rates: true,
      auto_shipments: true,
    },
    disabled_service_ids: [],
    shipping_ineligible_product_ids: [],
    product_weight_unit: 'kg',
    charge_actual_weight_only: false,
    service_coverage: {},
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
  const [services, setServices] = useState([]);
  const [servicesLoading, setServicesLoading] = useState(false);
  const [servicesError, setServicesError] = useState(null);
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState(null);

  const setFeature = (key, value) => {
    setSettings((prev) => ({
      ...prev,
      features: { ...prev.features, [key]: value },
    }));
  };

  const fetchShippingServices = useCallback(async () => {
    setServicesLoading(true);
    setServicesError(null);
    try {
      // @ts-ignore
      const response = await axios.get(window.tnxlData.apiUrl + '/shipping-services', {
        // @ts-ignore
        headers: { 'X-WP-Nonce': window.tnxlData.nonce }
      });
      setServices(response.data?.services || []);
    } catch (error) {
      setServices([]);
      setServicesError(error.response?.data?.message || 'Failed to load shipping services.');
    } finally {
      setServicesLoading(false);
    }
  }, []);

  const fetchSettings = useCallback(async () => {
    setLoading(true);
    try {
      // @ts-ignore
      const response = await axios.get(window.tnxlData.apiUrl + '/settings', {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxlData.nonce
        }
      });
      setSettings({
        ...response.data,
        features: {
          checkout_rates: response.data?.features?.checkout_rates !== false,
          auto_shipments: response.data?.features?.auto_shipments !== false,
        },
        disabled_service_ids: response.data?.disabled_service_ids || [],
        shipping_ineligible_product_ids: response.data?.shipping_ineligible_product_ids || [],
        product_weight_unit: response.data?.product_weight_unit === 'g' ? 'g' : 'kg',
        charge_actual_weight_only: Boolean(response.data?.charge_actual_weight_only),
        service_coverage: response.data?.service_coverage || {},
      });
      if (response.data?.api_token) {
        void fetchShippingServices();
      }
    } catch (error) {
      console.error('Failed to fetch settings', error);
    } finally {
      setLoading(false);
    }
  }, [fetchShippingServices]);

  useEffect(() => {
    const timeoutId = window.setTimeout(fetchSettings, 0);
    return () => window.clearTimeout(timeoutId);
  }, [fetchSettings]);

  const handleTestConnection = async () => {
    setTesting(true);
    setTestResult(null);
    try {
      // @ts-ignore
      const response = await axios.post(window.tnxlData.apiUrl + '/check-connection', {
        api_token: settings.api_token,
      }, {
        // @ts-ignore
        headers: { 'X-WP-Nonce': window.tnxlData.nonce }
      });
      setTestResult(response.data);
    } catch (error) {
      setTestResult({
        valid: false,
        message: error.response?.data?.message || 'Connection test failed.',
      });
    } finally {
      setTesting(false);
    }
  };

  const toggleService = (service, enabled) => {
    const ids = serviceKeys(service);
    setSettings((previous) => ({
      ...previous,
      disabled_service_ids: enabled
        ? previous.disabled_service_ids.filter((item) => !ids.includes(item))
        : [...new Set([...previous.disabled_service_ids, ...ids])],
    }));
  };

  const coverageFor = (service) => {
    for (const id of serviceKeys(service)) {
      if (settings.service_coverage?.[id]) return settings.service_coverage[id];
    }
    return { worldwide: true, countries: [] };
  };

  const setCoverage = (service, next) => {
    setSettings((prev) => {
      const merged = { ...(prev.service_coverage || {}) };
      for (const id of serviceKeys(service)) {
        merged[id] = next;
      }
      return { ...prev, service_coverage: merged };
    });
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    try {
      // @ts-ignore
      await axios.post(window.tnxlData.apiUrl + '/settings', settings, {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxlData.nonce
        }
      });
      setMessage({ type: 'success', text: 'Settings saved successfully!' });
      if (typeof onSaved === 'function') {
        onSaved(settings);
      }
      if (settings.api_token) {
        void fetchShippingServices();
      } else {
        setServices([]);
      }
      setTimeout(() => setMessage(null), 3000);
    } catch {
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
      {/* Feature toggles */}
      <div className="tnxl-card">
        <div className="bg-primary p-5 flex items-center gap-3">
          <Zap className="text-white w-6 h-6" />
          <h2 className="text-lg font-bold text-white">Automation</h2>
        </div>
        <div className="p-8 space-y-6">
          <div className="flex items-start justify-between gap-6 p-5 rounded-xl border border-gray-100 bg-gray-50/50">
            <div className="flex-1">
              <div className="flex items-center gap-2 font-semibold text-gray-800">
                <Package size={18} className="text-primary" />
                Real-time checkout shipping rates
              </div>
              <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                When enabled, live Thai Nexus rates are fetched at checkout based on cart dimensions and the customer shipping address.
                When disabled, no API quote calls are made during checkout (useful for staging or manual shipping workflows).
              </p>
            </div>
            <button
              type="button"
              role="switch"
              aria-checked={settings.features.checkout_rates}
              onClick={() => setFeature('checkout_rates', !settings.features.checkout_rates)}
              className="shrink-0 text-primary"
            >
              {settings.features.checkout_rates ? <ToggleRight size={40} /> : <ToggleLeft size={40} className="text-gray-300" />}
            </button>
          </div>

          <div className="flex items-start justify-between gap-6 p-5 rounded-xl border border-gray-100 bg-gray-50/50">
            <div className="flex-1">
              <div className="flex items-center gap-2 font-semibold text-gray-800">
                <Zap size={18} className="text-primary" />
                Automatic shipment creation
              </div>
              <p className="mt-2 text-sm text-gray-600 leading-relaxed">
                When enabled, shipments are created on the Thai Nexus platform when an order using this shipping method reaches Processing or Completed.
                Requires a valid API token and store origin address. Does not run if checkout rates are used without selecting a Thai Nexus method.
              </p>
            </div>
            <button
              type="button"
              role="switch"
              aria-checked={settings.features.auto_shipments}
              onClick={() => setFeature('auto_shipments', !settings.features.auto_shipments)}
              className="shrink-0 text-primary"
            >
              {settings.features.auto_shipments ? <ToggleRight size={40} /> : <ToggleLeft size={40} className="text-gray-300" />}
            </button>
          </div>

          {!settings.features.checkout_rates && !settings.features.auto_shipments && (
            <p className="text-sm text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-4 py-3">
              Both features are off. The plugin will not call the shipping quote API at checkout or create shipments automatically. You can still manage shipments from the dashboard.
            </p>
          )}
        </div>
      </div>

      {/* API Authentication */}
      <div className="tnxl-card">
        <div className="bg-primary p-5 flex items-center gap-3">
          <Key className="text-white w-6 h-6" />
          <h2 className="text-lg font-bold text-white">API Authentication</h2>
        </div>
        <div className="p-8">
          <div className="max-w-2xl">
            <label className="block text-sm font-semibold text-gray-700 mb-2">User Token</label>
            <input
              type="password"
              className="tnxl-input font-mono"
              placeholder="Enter your TNXL API Token"
              value={settings.api_token}
              onChange={(e) => setSettings({ ...settings, api_token: e.target.value })}
              required={settings.features.checkout_rates || settings.features.auto_shipments}
            />
            <p className="mt-3 text-sm text-gray-500 flex items-center gap-1.5">
              <AlertCircle size={14} className="text-primary" />
              Required when checkout rates or auto shipments are enabled. Found in: <a href="https://app.thainexus.co.th/" target="_blank" className="text-primary hover:underline font-medium">Profile Settings &gt; API Token</a>
            </p>
            <div className="mt-4 flex flex-wrap items-center gap-3">
              <button
                type="button"
                onClick={handleTestConnection}
                disabled={testing || !settings.api_token?.trim()}
                className="tnxl-btn-secondary disabled:opacity-50"
              >
                {testing ? <Loader2 className="animate-spin" size={18} /> : <Wifi size={18} />}
                {testing ? 'Testing...' : 'Test connection'}
              </button>
              {testResult && (
                <span className={`inline-flex items-center gap-2 text-sm font-medium px-3 py-2 rounded-lg ${
                  testResult.valid ? 'text-green-700 bg-green-50' : 'text-blue-700 bg-blue-50'
                }`}>
                  {testResult.valid ? <CheckCircle2 size={17} /> : <AlertCircle size={17} />}
                  {testResult.message}
                </span>
              )}
            </div>
            <p className="mt-2 text-xs text-gray-400">Testing checks the token but does not save it.</p>
          </div>
          
        </div>
      </div>

      <div className="tnxl-card">
        <div className="bg-primary p-5 flex items-center gap-3">
          <Package className="text-white w-6 h-6" />
          <h2 className="text-lg font-bold text-white">Product weight and billing</h2>
        </div>
        <div className="p-8 space-y-6">
          <div>
            <p className="text-sm font-semibold text-gray-700 mb-2">Product weight unit</p>
            <p className="text-sm text-gray-600 mb-3">
              How catalog product weights are read. Box inventory stays in kg. Thai Nexus quotes always use kg.
            </p>
            <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1">
              <button
                type="button"
                onClick={() => setSettings((s) => ({ ...s, product_weight_unit: 'kg' }))}
                className={`px-4 py-2 text-sm font-medium rounded-md ${
                  settings.product_weight_unit === 'kg' ? 'bg-white text-primary shadow-sm' : 'text-gray-600'
                }`}
              >
                KG
              </button>
              <button
                type="button"
                onClick={() => setSettings((s) => ({ ...s, product_weight_unit: 'g' }))}
                className={`px-4 py-2 text-sm font-medium rounded-md ${
                  settings.product_weight_unit === 'g' ? 'bg-white text-primary shadow-sm' : 'text-gray-600'
                }`}
              >
                Gram
              </button>
            </div>
          </div>
          <label className="flex items-start gap-3 cursor-pointer">
            <input
              type="checkbox"
              className="mt-1 w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary"
              checked={Boolean(settings.charge_actual_weight_only)}
              onChange={(e) => setSettings((s) => ({ ...s, charge_actual_weight_only: e.target.checked }))}
            />
            <span>
              <span className="block text-sm font-semibold text-gray-800">Charge actual product weight only</span>
              <span className="block text-sm text-gray-600 mt-1">
                Box packing is skipped. Quotes send filler dimensions so volumetric weight stays below product weight.
              </span>
            </span>
          </label>
          {settings.charge_actual_weight_only ? (
            <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
              <Info size={18} className="shrink-0 mt-0.5" />
              <p>The Boxes tab is hidden. Filler parcel size keeps volumetric weight below actual weight.</p>
            </div>
          ) : null}
        </div>
      </div>

      {/* Shipping services */}
      <div className="tnxl-card">
        <div className="bg-primary p-5 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <Truck className="text-white w-6 h-6" />
            <h2 className="text-lg font-bold text-white">Shipping Services</h2>
          </div>
          {services.length > 0 && (
            <span className="text-xs font-semibold text-white/80">
              {services.filter((service) => !settings.disabled_service_ids.includes(normalizeServiceId(service.id))).length} of {services.length} enabled
            </span>
          )}
        </div>
        <div className="p-8">
          {!settings.api_token ? (
            <p className="text-sm text-gray-500">Save an API token before choosing checkout services.</p>
          ) : servicesLoading ? (
            <div className="flex items-center gap-2 text-gray-500">
              <Loader2 size={18} className="animate-spin" />
              Loading services...
            </div>
          ) : servicesError ? (
            <div className="space-y-3">
              <p className="text-sm text-blue-700 bg-blue-50 rounded-lg px-4 py-3">{servicesError}</p>
              <button type="button" onClick={fetchShippingServices} className="tnxl-btn-secondary">
                Retry
              </button>
            </div>
          ) : services.length === 0 ? (
            <p className="text-sm text-gray-500">No shipping services were returned.</p>
          ) : (
            <div className="space-y-4">
              <p className="text-sm text-gray-600">
                Unchecked services will not appear as Thai Nexus checkout options. Choose All Available, selected countries, exclude, or rest of world per service.
              </p>
              <div className="flex gap-4 text-sm">
                <button
                  type="button"
                  className="text-primary font-medium hover:underline"
                  onClick={() => setSettings((previous) => ({ ...previous, disabled_service_ids: [] }))}
                >
                  Select all
                </button>
                <button
                  type="button"
                  className="text-primary font-medium hover:underline"
                  onClick={() => setSettings((previous) => ({
                    ...previous,
                    disabled_service_ids: services.map((service) => normalizeServiceId(service.id)),
                  }))}
                >
                  Deselect all
                </button>
              </div>
              <div className="divide-y divide-gray-100 border border-gray-100 rounded-xl">
                <ul>
                {services.map((service) => {
                  const id = normalizeServiceId(service.id);
                  const keys = serviceKeys(service);
                  const enabled = !keys.some((key) => settings.disabled_service_ids.includes(key));
                  const coverage = coverageFor(service);
                  const isExclude = Boolean(coverage.excludeCountries);
                  const isRestOfWorld = Boolean(coverage.restOfWorld) && !isExclude;
                  const isWorldwide = coverage.worldwide && !isRestOfWorld && !isExclude;
                  const isSelected = !isWorldwide && !isRestOfWorld && !isExclude;
                  return (
                    <li key={id} className="px-4 py-3 hover:bg-gray-50">
                      <label className="flex items-center gap-4 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={enabled}
                          onChange={(event) => toggleService(service, event.target.checked)}
                          className="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary"
                        />
                        {service.logo
                          ? <img src={service.logo} alt="" className="w-8 h-8 object-contain" />
                          : <Truck size={20} className="text-gray-400" />}
                        <span className="text-sm font-medium text-gray-800">
                          {service.service_name || service.name || service.id}
                        </span>
                      </label>
                      {enabled && (
                        <div className="mt-3 ml-8 space-y-3">
                          <div className="flex flex-wrap gap-4 text-sm">
                            <label className="inline-flex items-center gap-2 cursor-pointer">
                              <input
                                type="radio"
                                name={`coverage-${id}`}
                                checked={isWorldwide}
                                onChange={() => setCoverage(service, { worldwide: true, countries: [] })}
                              />
                              All Available
                            </label>
                            <label className="inline-flex items-center gap-2 cursor-pointer">
                              <input
                                type="radio"
                                name={`coverage-${id}`}
                                checked={isSelected}
                                onChange={() => setCoverage(service, { worldwide: false, countries: coverage.countries || [] })}
                              />
                              Selected countries
                            </label>
                            <label className="inline-flex items-center gap-2 cursor-pointer">
                              <input
                                type="radio"
                                name={`coverage-${id}`}
                                checked={isExclude}
                                onChange={() => setCoverage(service, { worldwide: true, excludeCountries: true, countries: coverage.countries || [] })}
                              />
                              Exclude countries
                            </label>
                            <label className="inline-flex items-center gap-2 cursor-pointer">
                              <input
                                type="radio"
                                name={`coverage-${id}`}
                                checked={isRestOfWorld}
                                onChange={() => setCoverage(service, { worldwide: false, restOfWorld: true, countries: [] })}
                              />
                              Rest of world
                            </label>
                          </div>
                          {isSelected && (
                            <CountryMultiSelect
                              selected={coverage.countries || []}
                              onChange={(countries) => setCoverage(service, { worldwide: false, countries })}
                            />
                          )}
                          {isExclude && (
                            <CountryMultiSelect
                              selected={coverage.countries || []}
                              placeholder="Search countries to exclude"
                              onChange={(countries) => setCoverage(service, { worldwide: true, excludeCountries: true, countries })}
                            />
                          )}
                          {isRestOfWorld && (
                            <p className="text-xs text-gray-500">Shown only when no other enabled service is offered for the destination.</p>
                          )}
                        </div>
                      )}
                    </li>
                  );
                })}
                </ul>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Product eligibility */}
      <div className="tnxl-card">
        <div className="bg-primary p-5 flex items-center gap-3">
          <Package className="text-white w-6 h-6" />
          <h2 className="text-lg font-bold text-white">Product Shipping Eligibility</h2>
        </div>
        <div className="p-8 space-y-5">
          <p className="text-sm text-gray-600">
            All products are eligible by default. Add products that must hide Thai Nexus rates whenever they are present in the cart.
          </p>
          <ProductSearchSelect
            selectedProducts={settings.shipping_ineligible_product_ids}
            onChange={(ids) => setSettings((previous) => ({
              ...previous,
              shipping_ineligible_product_ids: ids,
            }))}
            placeholder="Search products to exclude..."
            emptyLabel="No products are excluded."
          />
          <div className="flex items-start gap-3 rounded-lg bg-gray-50 border border-gray-100 p-4 text-sm text-gray-600">
            <Info size={18} className="text-primary shrink-0 mt-0.5" />
            <p>
              Product-level eligibility and boxed-product controls are also available under Product data &gt; Shipping on each WooCommerce product.
            </p>
          </div>
        </div>
      </div>

      {/* Shipper Address */}
      <div className="tnxl-card">
        <div className="bg-primary p-5 flex items-center gap-3">
          <MapPin className="text-white w-6 h-6" />
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
                className="tnxl-input"
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
                className="tnxl-input"
                value={settings.shipper.phone}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, phone: e.target.value } })}
                required
              />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-semibold text-gray-700 mb-2">Address</label>
              <textarea
                className="tnxl-input h-24 resize-none"
                value={settings.shipper.address}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, address: e.target.value } })}
                required
              ></textarea>
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2">City</label>
              <input
                type="text"
                className="tnxl-input"
                value={settings.shipper.city}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, city: e.target.value } })}
                required
              />
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2">State / Province</label>
              <input
                type="text"
                className="tnxl-input"
                value={settings.shipper.state || ''}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, state: e.target.value } })}
              />
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2">Postal Code</label>
              <input
                type="text"
                className="tnxl-input"
                value={settings.shipper.postal_code || ''}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, postal_code: e.target.value } })}
              />
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2">Country</label>
              <select
                className="tnxl-input bg-white"
                value={settings.shipper.country}
                onChange={(e) => setSettings({ ...settings, shipper: { ...settings.shipper, country: e.target.value } })}
              >
                <option value="TH">Thailand (TH)</option>
                <option value="US">United States (US)</option>
                <option value="GB">United Kingdom (GB)</option>
                <option value="AU">Australia (AU)</option>
                <option value="SG">Singapore (SG)</option>
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
          className="tnxl-btn-primary py-3 px-10 text-lg shadow-lg shadow-blue-100 disabled:opacity-50"
        >
          {saving ? <Loader2 className="animate-spin" /> : <Save size={20} />}
          {saving ? 'Saving...' : 'Save Settings'}
        </button>

        {message && (
          <div className={`flex items-center gap-2 font-medium px-4 py-2 rounded-lg animate-in fade-in zoom-in duration-300 ${
            message.type === 'success' ? 'text-green-600 bg-green-50' : 'text-blue-600 bg-blue-50'
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
