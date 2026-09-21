import { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, Info, Loader2, Package, Search, XCircle } from 'lucide-react';
import axios from 'axios';

// Native extra product-page editor is not needed for now. Merchants use this Products tab
// (Woo Product data > Shipping fields already exist and stay as-is).

const requestOptions = () => ({
  headers: { 'X-WP-Nonce': window.tnxlData.nonce },
});

const emptyForm = {
  length: '',
  width: '',
  height: '',
  weight: '',
  hs_code: '',
  country_of_origin: 'TH',
  is_document: false,
  is_boxed_product: false,
  shipping_eligible: true,
};

export default function ProductsPage() {
  const [search, setSearch] = useState('');
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async (q) => {
    setLoading(true);
    try {
      const response = await axios.get(
        `${window.tnxlData.apiUrl}/product-catalog?search=${encodeURIComponent(q || '')}`,
        requestOptions()
      );
      setProducts(Array.isArray(response.data) ? response.data : []);
    } catch {
      setProducts([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const t = window.setTimeout(() => void load(search), 300);
    return () => window.clearTimeout(t);
  }, [search, load]);

  const pick = (p) => {
    setSelected(p);
    setMessage('');
    setError('');
    setForm({
      length: p.length || '',
      width: p.width || '',
      height: p.height || '',
      weight: p.weight || '',
      hs_code: p.hs_code || '',
      country_of_origin: p.country_of_origin || 'TH',
      is_document: Boolean(p.is_document),
      is_boxed_product: Boolean(p.is_boxed_product),
      shipping_eligible: p.shipping_eligible !== false,
    });
  };

  const save = async (e) => {
    e.preventDefault();
    if (!selected) return;
    setSaving(true);
    setMessage('');
    setError('');
    try {
      const response = await axios.put(
        `${window.tnxlData.apiUrl}/products/${selected.id}`,
        {
          length: Number(form.length || 0),
          width: Number(form.width || 0),
          height: Number(form.height || 0),
          weight: Number(form.weight || 0),
          hs_code: form.hs_code,
          country_of_origin: form.country_of_origin,
          is_document: form.is_document,
          is_boxed_product: form.is_boxed_product,
          shipping_eligible: form.shipping_eligible,
        },
        requestOptions()
      );
      const saved = response.data;
      setSelected(saved);
      setForm({
        length: saved.length || '',
        width: saved.width || '',
        height: saved.height || '',
        weight: saved.weight || '',
        hs_code: saved.hs_code || '',
        country_of_origin: saved.country_of_origin || 'TH',
        is_document: Boolean(saved.is_document),
        is_boxed_product: Boolean(saved.is_boxed_product),
        shipping_eligible: saved.shipping_eligible !== false,
      });
      setProducts((rows) => rows.map((row) => (row.id === saved.id ? saved : row)));
      setMessage('Saved product dimensions and Thai Nexus flags.');
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const ready =
    Number(form.length) > 0 &&
    Number(form.width) > 0 &&
    Number(form.height) > 0 &&
    Number(form.weight) > 0;

  return (
    <div className="space-y-6">
      <div className="tnxl-card">
        <div className="bg-primary p-5 flex items-center gap-3">
          <Package className="text-white w-6 h-6" />
          <h2 className="text-lg font-bold text-white">Products</h2>
        </div>
        <div className="p-6 space-y-4">
          <div className="flex gap-3 rounded-lg border border-primary/15 bg-[#eff6ff] px-4 py-3 text-sm text-gray-700">
            <Info size={18} className="text-primary shrink-0 mt-0.5" />
            <p>
              Search, pick a product, then save weight, dimensions, HS, document, boxed, and
              eligibility here. The same fields remain on Product data &gt; Shipping.
            </p>
          </div>
          <div className="relative max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input
              type="search"
              className="tnxl-input !pl-9"
              placeholder="Search products..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th className="text-left px-5 py-3">Product</th>
                <th className="text-left px-5 py-3">L x W x H (cm)</th>
                <th className="text-left px-5 py-3">Weight (kg)</th>
                <th className="text-left px-5 py-3">HS</th>
                <th className="text-left px-5 py-3">Flags</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={5} className="py-12 text-center">
                    <Loader2 className="w-6 h-6 animate-spin mx-auto text-primary" />
                  </td>
                </tr>
              ) : products.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-12 text-center text-gray-500">
                    No products found.
                  </td>
                </tr>
              ) : (
                products.map((p) => (
                  <tr
                    key={p.id}
                    className={`border-t border-gray-100 cursor-pointer hover:bg-gray-50 ${
                      selected?.id === p.id ? 'bg-primary/5' : ''
                    }`}
                    onClick={() => pick(p)}
                  >
                    <td className="px-5 py-3">
                      <span className="font-medium text-primary">{p.name}</span>
                      {p.sku ? <p className="text-xs text-gray-400">SKU: {p.sku}</p> : null}
                    </td>
                    <td className="px-5 py-3 text-gray-600">
                      {p.length} x {p.width} x {p.height}
                    </td>
                    <td className="px-5 py-3 text-gray-600">{p.weight}</td>
                    <td className="px-5 py-3 font-mono text-xs">{p.hs_code || '-'}</td>
                    <td className="px-5 py-3 text-xs text-gray-600">
                      {[
                        p.shipping_eligible ? 'eligible' : 'ineligible',
                        p.is_document ? 'document' : null,
                        p.is_boxed_product ? 'boxed' : null,
                      ]
                        .filter(Boolean)
                        .join(', ')}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {selected && (
        <form onSubmit={save} className="tnxl-card p-6 space-y-4">
          <div className="flex items-start justify-between">
            <div>
              <h3 className="font-semibold text-primary">{selected.name}</h3>
              <p className="text-xs text-gray-400 mt-1">ID: {selected.id}</p>
            </div>
            {ready ? (
              <span className="inline-flex items-center gap-1 text-green-700 text-sm font-medium">
                <CheckCircle2 className="w-4 h-4" /> Ready for rates
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 text-amber-700 text-sm font-medium">
                <XCircle className="w-4 h-4" /> Missing weight or dims
              </span>
            )}
          </div>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <label className="text-sm">Length (cm)<input type="number" step="0.1" className="tnxl-input mt-1" value={form.length} onChange={(e) => setForm((f) => ({ ...f, length: e.target.value }))} /></label>
            <label className="text-sm">Width (cm)<input type="number" step="0.1" className="tnxl-input mt-1" value={form.width} onChange={(e) => setForm((f) => ({ ...f, width: e.target.value }))} /></label>
            <label className="text-sm">Height (cm)<input type="number" step="0.1" className="tnxl-input mt-1" value={form.height} onChange={(e) => setForm((f) => ({ ...f, height: e.target.value }))} /></label>
            <label className="text-sm">Weight (kg)<input type="number" step="0.01" className="tnxl-input mt-1" value={form.weight} onChange={(e) => setForm((f) => ({ ...f, weight: e.target.value }))} /></label>
          </div>
          <label className="text-sm block max-w-sm">HS code<input className="tnxl-input mt-1" value={form.hs_code} onChange={(e) => setForm((f) => ({ ...f, hs_code: e.target.value }))} /></label>
          <label className="text-sm block max-w-sm">Country of origin<input className="tnxl-input mt-1" maxLength={2} value={form.country_of_origin} onChange={(e) => setForm((f) => ({ ...f, country_of_origin: e.target.value.toUpperCase() }))} placeholder="TH" /></label>
          <div className="flex flex-wrap gap-6 text-sm">
            <label className="inline-flex items-center gap-2"><input type="checkbox" checked={form.is_document} onChange={(e) => setForm((f) => ({ ...f, is_document: e.target.checked }))} /> Document</label>
            <label className="inline-flex items-center gap-2"><input type="checkbox" checked={form.is_boxed_product} onChange={(e) => setForm((f) => ({ ...f, is_boxed_product: e.target.checked }))} /> Boxed product</label>
            <label className="inline-flex items-center gap-2"><input type="checkbox" checked={form.shipping_eligible} onChange={(e) => setForm((f) => ({ ...f, shipping_eligible: e.target.checked }))} /> Shipping eligible</label>
          </div>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          {message ? <p className="text-sm text-primary">{message}</p> : null}
          <button type="submit" disabled={saving} className="tnxl-btn-primary disabled:opacity-50">
            {saving ? 'Saving...' : 'Save product'}
          </button>
        </form>
      )}
    </div>
  );
}
