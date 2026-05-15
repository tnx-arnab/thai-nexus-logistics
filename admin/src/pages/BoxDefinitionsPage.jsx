import React, { useState, useEffect } from 'react';
import { Package, Plus, Trash2, Save, Loader2, CheckCircle2, AlertCircle, Info, Move } from 'lucide-react';
import axios from 'axios';

const BoxDefinitionsPage = () => {
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null);
  const [boxes, setBoxes] = useState([]);

  useEffect(() => {
    fetchBoxes();
  }, []);

  const fetchBoxes = async () => {
    setLoading(true);
    try {
      // @ts-ignore
      const response = await axios.get(window.tnxlData.apiUrl + '/box-definitions', {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxlData.nonce
        }
      });
      setBoxes(response.data || []);
    } catch (error) {
      console.error('Failed to fetch boxes', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async () => {
    setSaving(true);
    setMessage(null);
    try {
      // @ts-ignore
      await axios.post(window.tnxlData.apiUrl + '/box-definitions', { boxes }, {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxlData.nonce
        }
      });
      setMessage({ type: 'success', text: 'Box definitions saved successfully!' });
      setTimeout(() => setMessage(null), 3000);
    } catch (error) {
      setMessage({ type: 'error', text: 'Failed to save box definitions.' });
    } finally {
      setSaving(false);
    }
  };

  const addBox = () => {
    setBoxes([
      ...boxes,
      {
        name: '',
        inner_length: 0,
        inner_width: 0,
        inner_depth: 0,
        outer_length: 0,
        outer_width: 0,
        outer_depth: 0,
        max_weight: 0,
        empty_weight: 0,
      }
    ]);
  };

  const removeBox = (index) => {
    const newBoxes = [...boxes];
    newBoxes.splice(index, 1);
    setBoxes(newBoxes);
  };

  const updateBox = (index, field, value) => {
    const newBoxes = [...boxes];
    const box = newBoxes[index];
    box[field] = value;

    // Auto-sync outer dimensions
    if (field === 'inner_length') box.outer_length = value;
    if (field === 'inner_width') box.outer_width = value;
    if (field === 'inner_depth') box.outer_depth = value;

    setBoxes(newBoxes);
  };

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center py-20 bg-white rounded-2xl border border-gray-100 shadow-sm">
        <Loader2 className="w-10 h-10 text-primary animate-spin" />
        <p className="text-gray-500 mt-4 font-medium">Loading box definitions...</p>
      </div>
    );
  }

  return (
    <div className="space-y-8">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-secondary">Box Inventory</h2>
          <p className="text-gray-500">Define the physical boxes you use for shipping.</p>
        </div>
        <button
          onClick={addBox}
          className="tnxl-btn-primary py-2.5 px-6 flex items-center gap-2"
        >
          <Plus size={18} /> Add New Box
        </button>
      </div>

      {boxes.length === 0 ? (
        <div className="bg-white rounded-2xl border-2 border-dashed border-gray-200 p-20 flex flex-col items-center justify-center text-center">
          <div className="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mb-6">
            <Package className="text-gray-300 w-10 h-10" />
          </div>
          <h3 className="text-xl font-bold text-secondary mb-2">No boxes defined</h3>
          <p className="text-gray-500 max-w-sm mb-8">Add your first shipping box to enable the 3D Box Packing algorithm.</p>
          <button
            onClick={addBox}
            className="tnxl-btn-primary py-2.5 px-8"
          >
            Add First Box
          </button>
        </div>
      ) : (
        <div className="space-y-6">
          {boxes.map((box, index) => (
            <div key={index} className="tnxl-card group overflow-visible">
              <div className="bg-gray-50 p-4 border-b border-gray-100 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 bg-white rounded-lg border border-gray-200 flex items-center justify-center text-secondary font-bold text-sm">
                    {index + 1}
                  </div>
                  <input
                    type="text"
                    className="bg-transparent border-none font-bold text-secondary focus:ring-0 p-0 text-lg"
                    placeholder="Box Name (e.g. Small Mailer)"
                    value={box.name}
                    onChange={(e) => updateBox(index, 'name', e.target.value)}
                  />
                </div>
                <button
                  onClick={() => removeBox(index)}
                  className="text-gray-400 hover:text-blue-500 transition-colors p-2"
                  title="Remove Box"
                >
                  <Trash2 size={18} />
                </button>
              </div>
              <div className="p-6">
                <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-6">
                  <div className="md:col-span-3 lg:col-span-3 grid grid-cols-3 gap-4">
                    <div>
                      <label className="block text-xs font-bold text-gray-400 uppercase mb-2">Inner Length (cm)</label>
                      <input
                        type="number"
                        className="tnxl-input"
                        value={box.inner_length}
                        onChange={(e) => updateBox(index, 'inner_length', e.target.value)}
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-bold text-gray-400 uppercase mb-2">Inner Width (cm)</label>
                      <input
                        type="number"
                        className="tnxl-input"
                        value={box.inner_width}
                        onChange={(e) => updateBox(index, 'inner_width', e.target.value)}
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-bold text-gray-400 uppercase mb-2">Inner Height (cm)</label>
                      <input
                        type="number"
                        className="tnxl-input"
                        value={box.inner_depth}
                        onChange={(e) => updateBox(index, 'inner_depth', e.target.value)}
                      />
                    </div>
                  </div>
                  <div className="md:col-span-3 lg:col-span-3 grid grid-cols-3 gap-4 border-l border-gray-100 pl-4">
                    <div>
                      <label className="block text-xs font-bold text-gray-400 uppercase mb-2">Empty Weight (kg)</label>
                      <input
                        type="number"
                        step="0.01"
                        className="tnxl-input"
                        value={box.empty_weight}
                        onChange={(e) => updateBox(index, 'empty_weight', e.target.value)}
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-bold text-gray-400 uppercase mb-2">Max Weight (kg)</label>
                      <input
                        type="number"
                        step="0.1"
                        className="tnxl-input"
                        value={box.max_weight}
                        onChange={(e) => updateBox(index, 'max_weight', e.target.value)}
                      />
                    </div>
                    <div className="flex items-end justify-end">
                       <div className="bg-blue-50 text-blue-700 p-2 rounded-lg text-xs flex items-start gap-2 max-w-[200px]">
                         <Info size={14} className="mt-0.5 shrink-0" />
                         <span>BoxPacker uses inner dimensions for fitting.</span>
                       </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          ))}

          <div className="flex items-center gap-6 pt-4">
            <button
              onClick={handleSave}
              disabled={saving}
              className="tnxl-btn-primary py-3 px-10 text-lg shadow-lg shadow-blue-100 disabled:opacity-50"
            >
              {saving ? <Loader2 className="animate-spin" /> : <Save size={20} />}
              {saving ? 'Saving...' : 'Save Box Inventory'}
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
        </div>
      )}
    </div>
  );
};

export default BoxDefinitionsPage;
