import { useEffect, useRef, useState } from 'react';
import { Loader2, Package, Search, X } from 'lucide-react';
import axios from 'axios';

const requestOptions = () => ({
  // @ts-ignore
  headers: { 'X-WP-Nonce': window.tnxlData.nonce },
});

const ProductSearchSelect = ({
  selectedProducts,
  onChange,
  placeholder = 'Search products to add...',
  emptyLabel = 'No products selected.',
}) => {
  const [search, setSearch] = useState('');
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const [showDropdown, setShowDropdown] = useState(false);
  const [selectedDetails, setSelectedDetails] = useState([]);
  const dropdownRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    const ids = selectedProducts.map(Number).filter(Boolean);

    if (ids.length === 0) {
      setSelectedDetails([]);
      return () => {
        cancelled = true;
      };
    }

    // @ts-ignore
    axios.get(`${window.tnxlData.apiUrl}/products?ids=${ids.join(',')}`, requestOptions())
      .then((response) => {
        if (cancelled) return;
        const found = Array.isArray(response.data) ? response.data : [];
        const byId = new Map(found.map((product) => [Number(product.id), product]));
        setSelectedDetails(ids.map((id) => byId.get(id) || { id, name: `Product #${id}` }));
      })
      .catch(() => {
        if (!cancelled) {
          setSelectedDetails(ids.map((id) => ({ id, name: `Product #${id}` })));
        }
      });

    return () => {
      cancelled = true;
    };
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
    const timer = setTimeout(async () => {
      if (!search.trim()) {
        setResults([]);
        return;
      }

      setSearching(true);
      try {
        // @ts-ignore
        const response = await axios.get(
          `${window.tnxlData.apiUrl}/search-products?search=${encodeURIComponent(search.trim())}`,
          requestOptions()
        );
        setResults(Array.isArray(response.data) ? response.data : []);
      } catch {
        setResults([]);
      } finally {
        setSearching(false);
      }
    }, 400);

    return () => clearTimeout(timer);
  }, [search]);

  const handleSelect = (product) => {
    const id = Number(product.id);
    if (!selectedProducts.map(Number).includes(id)) {
      onChange([...selectedProducts, id]);
    }
    setSearch('');
    setShowDropdown(false);
  };

  const handleRemove = (id) => {
    onChange(selectedProducts.filter((productId) => Number(productId) !== Number(id)));
  };

  const selectedIdSet = new Set(selectedProducts.map(Number));
  const visibleSelectedDetails = selectedDetails.filter((product) => selectedIdSet.has(Number(product.id)));

  return (
    <div className="relative" ref={dropdownRef}>
      <div className="flex flex-wrap gap-2 mb-3">
        {visibleSelectedDetails.length === 0 && (
          <span className="text-sm text-gray-400">{emptyLabel}</span>
        )}
        {visibleSelectedDetails.map((product) => (
          <span key={product.id} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary/5 text-primary text-xs font-semibold border border-primary/20">
            {product.name}
            <button
              type="button"
              onClick={() => handleRemove(product.id)}
              className="hover:bg-primary/10 rounded-full p-0.5"
              aria-label={`Remove ${product.name}`}
            >
              <X size={12} />
            </button>
          </span>
        ))}
      </div>

      <div className="relative">
        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
          {searching
            ? <Loader2 size={16} className="text-gray-400 animate-spin" />
            : <Search size={16} className="text-gray-400" />}
        </div>
        <input
          type="text"
          className="tnxl-input !pl-12"
          placeholder={placeholder}
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setShowDropdown(true);
          }}
          onFocus={() => setShowDropdown(true)}
        />
      </div>

      {showDropdown && search.trim() && (
        <div className="absolute z-[100] mt-2 w-full bg-white shadow-xl rounded-xl border border-gray-100 py-2 max-h-72 overflow-y-auto">
          {!searching && results.length === 0 ? (
            <div className="px-4 py-8 text-center text-sm text-gray-400">
              No products found.
            </div>
          ) : (
            results.map((product) => (
              <button
                key={product.id}
                type="button"
                className="w-full text-left px-4 py-3 hover:bg-gray-50 flex items-center gap-3 border-b border-gray-50 last:border-0"
                onClick={() => handleSelect(product)}
              >
                <div className="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400 shrink-0">
                  <Package size={18} />
                </div>
                <div className="flex flex-col min-w-0">
                  <span className="font-semibold text-gray-900 truncate">{product.name}</span>
                  <span className="text-gray-400 text-[10px] uppercase tracking-wider font-bold">
                    {product.sku ? `SKU: ${product.sku} - ` : ''}ID: {product.id}
                  </span>
                </div>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
};

export default ProductSearchSelect;
