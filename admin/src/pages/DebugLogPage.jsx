import React, { useState, useEffect } from 'react';
import { Trash2, RefreshCw, ChevronDown, ChevronUp, Package, Box, Zap, MapPin, Terminal, Activity, FileText } from 'lucide-react';
import axios from 'axios';

const DebugLogPage = () => {
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(false);
  const [expandedId, setExpandedId] = useState(null);
  const [autoRefresh, setAutoRefresh] = useState(false);

  useEffect(() => {
    fetchLogs();
  }, []);

  useEffect(() => {
    let interval;
    if (autoRefresh) {
      interval = setInterval(fetchLogs, 5000);
    }
    return () => clearInterval(interval);
  }, [autoRefresh]);

  const fetchLogs = async () => {
    setLoading(true);
    try {
      // @ts-ignore
      const response = await axios.get(window.tnxData.apiUrl + '/debug-log', {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxData.nonce
        }
      });
      setLogs(response.data);
    } catch (error) {
      console.error('Failed to fetch debug logs', error);
    } finally {
      setLoading(false);
    }
  };

  const clearLogs = async () => {
    if (!window.confirm('Are you sure you want to clear all debug logs?')) return;
    try {
      // @ts-ignore
      await axios.delete(window.tnxData.apiUrl + '/debug-log', {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxData.nonce
        }
      });
      setLogs([]);
    } catch (error) {
      console.error('Failed to clear logs', error);
    }
  };

  const toggleExpand = (id) => {
    setExpandedId(expandedId === id ? null : id);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-secondary flex items-center gap-2">
            <Terminal className="text-amber-500" />
            Developer Debug Log
          </h2>
          <p className="text-gray-500 text-sm">Capture real-time shipping calculation data and API payloads.</p>
        </div>
        
        <div className="flex items-center gap-3">
          <label className="flex items-center gap-2 cursor-pointer bg-white px-3 py-2 rounded-lg border border-gray-200 text-sm font-medium">
            <input 
              type="checkbox" 
              checked={autoRefresh} 
              onChange={(e) => setAutoRefresh(e.target.checked)}
              className="rounded border-gray-300 text-primary focus:ring-primary"
            />
            Auto-refresh (5s)
          </label>
          <button 
            onClick={fetchLogs} 
            className="flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium"
          >
            <RefreshCw size={16} className={loading ? 'animate-spin' : ''} />
            Refresh
          </button>
          <button 
            onClick={clearLogs} 
            className="flex items-center gap-2 px-4 py-2 bg-red-50 text-red-600 border border-red-100 rounded-lg hover:bg-red-100 transition-colors text-sm font-medium"
          >
            <Trash2 size={16} />
            Clear All
          </button>
        </div>
      </div>

      <div className="bg-amber-50 border border-amber-100 rounded-xl p-4 flex items-start gap-3">
        <Zap size={20} className="text-amber-600 mt-0.5 shrink-0" />
        <div className="text-sm text-amber-800">
          <p className="font-bold">Debugging is ACTIVE</p>
          <p>Every shipping calculation at checkout is being recorded. This feature will be hidden in the final release.</p>
        </div>
      </div>

      {logs.length === 0 ? (
        <div className="tnx-card p-20 flex flex-col items-center justify-center text-center">
          <Activity size={48} className="text-gray-200 mb-4" />
          <h3 className="text-lg font-bold text-gray-700">No logs captured yet</h3>
          <p className="text-gray-500 max-w-sm mt-2">Trigger a shipping calculation on your cart or checkout page to see data here.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {logs.map((log) => (
            <div key={log.id} className="tnx-card overflow-hidden transition-all duration-200">
              {/* Log Header */}
              <div 
                onClick={() => toggleExpand(log.id)}
                className="p-5 flex flex-wrap items-center justify-between gap-4 cursor-pointer hover:bg-gray-50 transition-colors"
              >
                <div className="flex items-center gap-4">
                  <div className="bg-gray-100 text-gray-500 text-[10px] font-mono px-2 py-1 rounded">
                    {log.timestamp}
                  </div>
                  <div className="flex items-center gap-2 font-medium text-gray-800">
                    <Package size={16} className="text-primary" />
                    {log.products.length} Items
                  </div>
                  <div className="flex items-center gap-2 font-medium text-gray-800">
                    <Box size={16} className="text-blue-500" />
                    {log.box_count} Boxes
                  </div>
                </div>

                <div className="flex items-center gap-6">
                  <div className="flex items-center gap-1.5 text-sm text-gray-600">
                    <MapPin size={14} />
                    {log.destination.city}, {log.destination.country}
                  </div>
                  {expandedId === log.id ? <ChevronUp size={20} /> : <ChevronDown size={20} />}
                </div>
              </div>

              {/* Log Details */}
              {expandedId === log.id && (
                <div className="p-6 border-t border-gray-100 bg-gray-50/50 space-y-6">
                  <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Products & Packing */}
                    <div className="space-y-4">
                      <h4 className="font-bold text-secondary flex items-center gap-2 uppercase tracking-wider text-xs">
                        <FileText size={14} /> Products in Cart
                      </h4>
                      <div className="bg-white rounded-xl border border-gray-100 overflow-hidden shadow-sm">
                        <table className="w-full text-sm">
                          <thead>
                            <tr className="bg-gray-50 text-gray-500 font-medium border-bottom border-gray-100">
                              <th className="px-4 py-2 text-left">ID</th>
                              <th className="px-4 py-2 text-left">Product</th>
                              <th className="px-4 py-2 text-center">Qty</th>
                              <th className="px-4 py-2 text-right">Dims/Wt</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-gray-100">
                            {log.products.map((p, idx) => (
                              <tr key={idx}>
                                <td className="px-4 py-2 font-mono text-xs text-gray-400">{p.id}</td>
                                <td className="px-4 py-2 font-medium text-gray-700">{p.title}</td>
                                <td className="px-4 py-2 text-center">{p.qty}</td>
                                <td className="px-4 py-2 text-right text-xs text-gray-500">
                                  {p.dimensions}<br/>{p.weight}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>

                      <h4 className="font-bold text-secondary flex items-center gap-2 uppercase tracking-wider text-xs mt-6">
                        <Box size={14} /> Packing Results
                      </h4>
                      <div className="space-y-2">
                        {log.boxes.map((box, idx) => (
                          <div key={idx} className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm text-sm">
                            <div className="flex justify-between items-center mb-1">
                              <span className="font-bold text-gray-800">{box.name}</span>
                              <span className="text-gray-500 text-xs font-mono">{box.length}x{box.width}x{box.height} cm | {box.weight} kg</span>
                            </div>
                            <div className="text-xs text-gray-500 italic">
                              Items: {box.items.join(', ')}
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* API Details */}
                    <div className="space-y-4">
                      <h4 className="font-bold text-secondary flex items-center gap-2 uppercase tracking-wider text-xs">
                        <Zap size={14} /> API Transactions
                      </h4>
                      <div className="space-y-4">
                        {log.api_calls.map((call, idx) => (
                          <div key={idx} className="bg-secondary rounded-xl overflow-hidden border border-gray-800">
                            <div className="bg-gray-800 px-4 py-2 flex justify-between items-center">
                              <span className="text-xs font-mono text-gray-400">Box #{idx + 1} &gt; {call.endpoint}</span>
                              <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded ${call.status === 200 ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'}`}>
                                HTTP {call.status}
                              </span>
                            </div>
                            <div className="p-3 space-y-3">
                              <div>
                                <p className="text-[10px] text-gray-500 font-bold mb-1">PAYLOAD</p>
                                <pre className="bg-black/30 p-2 rounded text-[10px] text-amber-200 overflow-x-auto font-mono">
                                  {JSON.stringify(call.payload, null, 2)}
                                </pre>
                              </div>
                              <div>
                                <p className="text-[10px] text-gray-500 font-bold mb-1">RESPONSE</p>
                                <pre className="bg-black/30 p-2 rounded text-[10px] text-green-200 overflow-x-auto font-mono max-h-40 overflow-y-auto">
                                  {JSON.stringify(call.response, null, 2)}
                                </pre>
                              </div>
                            </div>
                          </div>
                        ))}
                      </div>

                      <h4 className="font-bold text-secondary flex items-center gap-2 uppercase tracking-wider text-xs mt-6">
                        <Activity size={14} /> Aggregated Quotes
                      </h4>
                      <div className="bg-white rounded-xl border border-gray-100 overflow-hidden shadow-sm">
                         <table className="w-full text-sm">
                          <thead>
                            <tr className="bg-gray-50 text-gray-500 font-medium border-bottom border-gray-100">
                              <th className="px-4 py-2 text-left">Courier</th>
                              <th className="px-4 py-2 text-center">Days</th>
                              <th className="px-4 py-2 text-right">Final Cost</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-gray-100">
                            {log.final_quotes.map((q, idx) => (
                              <tr key={idx}>
                                <td className="px-4 py-2 font-medium text-gray-700">{q.courier}</td>
                                <td className="px-4 py-2 text-center">{q.days}</td>
                                <td className="px-4 py-2 text-right font-bold text-primary">
                                  {new Intl.NumberFormat('en-US', { style: 'currency', currency: log.currency }).format(q.final_cost)}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default DebugLogPage;
