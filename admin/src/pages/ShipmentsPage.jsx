import React, { useState, useEffect } from 'react';
import { Package, Search, ChevronLeft, ChevronRight, Loader2, Calendar, Weight, Info, X, MapPin, Phone, User, Tag, AlertCircle, Key, ArrowRight } from 'lucide-react';
import axios from 'axios';

const ShipmentsPage = () => {
  const [loading, setLoading] = useState(false);
  const [shipments, setShipments] = useState([]);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [selectedShipment, setSelectedShipment] = useState(null);
  const [detailsLoading, setDetailsLoading] = useState(false);
  const [errorType, setErrorType] = useState(null); // 'auth' | 'general'

  useEffect(() => {
    fetchShipments();
  }, [page]);

  const fetchShipments = async () => {
    setLoading(true);
    setErrorType(null);
    try {
      // @ts-ignore
      const response = await axios.get(`${window.tnxData.apiUrl}/shipments?page=${page}&limit=10`, {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxData.nonce
        }
      });
      setShipments(response.data.data || []);
      setTotal(response.data.pagination?.total || response.data.total || 0);
    } catch (error) {
      console.error('Failed to fetch shipments', error);
      const status = error.response?.status;
      if (status === 401 || status === 403 || status === 405) {
        setErrorType('auth');
      } else {
        setErrorType('general');
      }
      setShipments([]);
      setTotal(0);
    } finally {
      setLoading(false);
    }
  };

  const fetchShipmentDetails = async (requestNumber) => {
    setDetailsLoading(true);
    setSelectedShipment({ request_number: requestNumber }); // Placeholder for animation
    try {
      // @ts-ignore
      const response = await axios.get(`${window.tnxData.apiUrl}/shipments/${requestNumber}`, {
        headers: {
          // @ts-ignore
          'X-WP-Nonce': window.tnxData.nonce
        }
      });
      setSelectedShipment(response.data);
    } catch (error) {
      console.error('Failed to fetch details', error);
      alert('Could not load shipment details.');
      setSelectedShipment(null);
    } finally {
      setDetailsLoading(false);
    }
  };

  const getStatusColor = (status, isHeader = false) => {
    const s = status?.toLowerCase() || '';
    if (s.includes('submit') || s.includes('pending')) {
        return isHeader ? 'bg-blue-500/20 text-blue-100 border-blue-400/30' : 'bg-blue-100 text-blue-700 border-blue-200';
    }
    if (s.includes('process') || s.includes('transit')) {
        return isHeader ? 'bg-amber-500/20 text-amber-100 border-amber-400/30' : 'bg-amber-100 text-amber-700 border-amber-200';
    }
    if (s.includes('deliver')) {
        return isHeader ? 'bg-green-500/20 text-green-100 border-green-400/30' : 'bg-green-100 text-green-700 border-green-200';
    }
    if (s.includes('cancel') || s.includes('lost')) {
        return isHeader ? 'bg-red-500/20 text-red-100 border-red-400/30' : 'bg-red-100 text-red-700 border-red-200';
    }
    return isHeader ? 'bg-gray-500/20 text-gray-100 border-gray-400/30' : 'bg-gray-100 text-gray-700 border-gray-200';
  };

  return (
    <div className="space-y-4">
      {/* Shipments Table Card */}
      <div className="tnx-card transition-all hover:shadow-sm">
        <div className="p-4 border-b border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <h2 className="text-lg font-bold text-secondary flex items-center gap-2">
            Recent Shipments
            <span className="text-[10px] font-bold bg-gray-100 text-gray-400 px-2 py-0.5 rounded-full">
              {total || shipments.length} total
            </span>
          </h2>
          
          <div className="relative group">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-3.5 h-3.5 z-10 group-focus-within:text-primary transition-colors" />
            <input 
              type="text" 
              placeholder="Search request number..." 
              className="tnx-input !pl-9 py-1.5 text-xs w-full md:w-56 relative border-gray-100 bg-gray-50/50 focus:bg-white"
            />
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-gray-50/20">
                <th className="px-5 py-3 text-[10px] font-bold text-gray-400 uppercase tracking-widest">Request Number</th>
                <th className="px-5 py-3 text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</th>
                <th className="px-5 py-3 text-[10px] font-bold text-gray-400 uppercase tracking-widest">Vol. Weight</th>
                <th className="px-5 py-3 text-[10px] font-bold text-gray-400 uppercase tracking-widest">Date</th>
                <th className="px-5 py-3 text-[10px] font-bold text-gray-400 uppercase tracking-widest text-right">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {loading ? (
                <tr>
                  <td colSpan={5} className="py-12 text-center">
                    <Loader2 className="w-6 h-6 text-primary animate-spin mx-auto" />
                    <p className="text-gray-400 mt-2 text-xs">Loading...</p>
                  </td>
                </tr>
              ) : errorType === 'auth' ? (
                <tr>
                  <td colSpan={5} className="py-16 text-center animate-in fade-in zoom-in duration-300">
                    <Key className="w-12 h-12 text-red-100 mx-auto mb-4" />
                    <h3 className="text-lg font-bold text-secondary">Connection Required</h3>
                    <p className="text-gray-500 mt-1 text-sm max-w-xs mx-auto">
                      Please check your API token in Settings.
                    </p>
                  </td>
                </tr>
              ) : shipments.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-16 text-center animate-in fade-in zoom-in duration-300">
                    <Package className="w-12 h-12 text-gray-100 mx-auto mb-4" />
                    <h3 className="text-lg font-bold text-secondary">No Shipments</h3>
                    <p className="text-gray-500 mt-1 text-sm">Waiting for your first order.</p>
                  </td>
                </tr>
              ) : (
                shipments.map((shipment, index) => (
                  <tr 
                    key={shipment.id} 
                    style={{ animationDelay: `${index * 30}ms` }}
                    className="hover:bg-blue-50/20 transition-all group animate-in fade-in slide-in-from-left-1 duration-200"
                  >
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-2.5">
                        <div className="w-7 h-7 rounded-lg bg-gray-50 flex items-center justify-center text-gray-400 group-hover:bg-primary group-hover:text-white transition-all">
                          <Package size={14} />
                        </div>
                        <span className="font-bold text-secondary text-sm">{shipment.request_number}</span>
                      </div>
                    </td>
                    <td className="px-5 py-3">
                      <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold border ${getStatusColor(shipment.status)}`}>
                        {shipment.status}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-gray-600 text-sm font-medium">
                      {shipment.volumetric_weight_kg || shipment.data?.volumetric_weight_kg || '0'} kg
                    </td>
                    <td className="px-5 py-3 text-gray-400 text-xs">
                      {new Date(shipment.submitted_date || shipment.created_at).toLocaleDateString()}
                    </td>
                    <td className="px-5 py-3 text-right">
                      <button 
                        onClick={() => fetchShipmentDetails(shipment.request_number)}
                        className="text-gray-300 hover:text-primary p-1.5 hover:bg-white rounded-md transition-all shadow-none hover:shadow-sm"
                      >
                        <ArrowRight size={16} />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {shipments.length > 0 && (
          <div className="p-4 border-t border-gray-50 flex items-center justify-between bg-gray-50/10">
            <p className="text-xs text-gray-400">
              Showing <span className="font-bold text-secondary">{(page-1)*10 + 1}</span> to <span className="font-bold text-secondary">{Math.min(page*10, total || shipments.length)}</span>
            </p>
            <div className="flex gap-1.5">
              <button 
                disabled={page === 1 || loading}
                onClick={() => setPage(p => p - 1)}
                className="p-1.5 border border-gray-200 rounded-lg hover:bg-white disabled:opacity-30 transition-all bg-white/50"
              >
                <ChevronLeft size={16} />
              </button>
              <button 
                disabled={loading || (total > 0 && page * 10 >= total)}
                onClick={() => setPage(p => p + 1)}
                className="p-1.5 border border-gray-200 rounded-lg hover:bg-white disabled:opacity-30 transition-all bg-white/50"
              >
                <ChevronRight size={16} />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Shipment Details Modal */}
      {selectedShipment && (
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-secondary/50 backdrop-blur-sm animate-in fade-in duration-300">
          <div className="bg-white rounded-[1.5rem] shadow-2xl w-full max-w-3xl overflow-hidden animate-in zoom-in-95 slide-in-from-bottom-4 duration-400">
            {/* Modal Header - COMPACT GRADIENT */}
            <div className="bg-gradient-to-r from-[#272262] to-[#362e8a] p-5 md:p-6 flex items-center justify-between relative overflow-hidden">
              <div className="flex items-center gap-4 relative z-10">
                <div className="w-12 h-12 rounded-xl bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center text-white shadow-lg">
                  <Package size={24} />
                </div>
                <div>
                  <h3 className="text-2xl font-black text-white tracking-tight leading-none mb-1.5">
                    {selectedShipment.request_number}
                  </h3>
                  <div className="flex items-center gap-2">
                    <span className={`px-2.5 py-0.5 rounded-full text-[9px] font-black border uppercase tracking-wider ${getStatusColor(selectedShipment.status, true)}`}>
                      {selectedShipment.status}
                    </span>
                    <span className="text-white/40 text-[9px] font-bold">
                      {new Date(selectedShipment.submitted_date || selectedShipment.created_at).toLocaleDateString()}
                    </span>
                  </div>
                </div>
              </div>
              <button 
                onClick={() => setSelectedShipment(null)}
                className="p-2 bg-white/5 hover:bg-white/15 text-white rounded-xl transition-all border border-white/10 group shadow-sm"
              >
                <X size={20} className="group-hover:scale-110 transition-transform" />
              </button>
            </div>

            <div className="p-6 md:p-8 max-h-[70vh] overflow-y-auto custom-scrollbar">
              {detailsLoading ? (
                 <div className="py-16 text-center">
                    <Loader2 className="w-8 h-8 text-primary animate-spin mx-auto mb-3" />
                    <p className="text-gray-400 font-bold uppercase tracking-widest text-[9px]">Syncing...</p>
                 </div>
              ) : (
                <div className="space-y-8">
                  {/* Addresses Section */}
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Shipper */}
                    <div className="space-y-3">
                      <h4 className="text-[9px] font-black text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                        <div className="w-1 h-1 rounded-full bg-primary" /> Shipper
                      </h4>
                      <div className="bg-gray-50/50 p-5 rounded-2xl border border-gray-100">
                        <p className="font-black text-secondary text-lg leading-tight">{selectedShipment.shipper_address?.name}</p>
                        <div className="space-y-2 mt-4">
                          <p className="text-xs text-gray-500 font-bold flex items-center gap-2">
                            <Phone size={12} className="text-primary" /> {selectedShipment.shipper_address?.phone}
                          </p>
                          <p className="text-xs text-gray-600 leading-relaxed flex items-start gap-2">
                            <MapPin size={12} className="text-primary mt-0.5 shrink-0" /> 
                            <span>{selectedShipment.shipper_address?.address_line1 || selectedShipment.shipper_address?.address}, <span className="font-bold text-gray-400">{selectedShipment.shipper_address?.city}, {selectedShipment.shipper_address?.country}</span></span>
                          </p>
                        </div>
                      </div>
                    </div>

                    {/* Consignee */}
                    <div className="space-y-3">
                      <h4 className="text-[9px] font-black text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                        <div className="w-1 h-1 rounded-full bg-blue-500" /> Consignee
                      </h4>
                      <div className="bg-gray-50/50 p-5 rounded-2xl border border-gray-100">
                        <p className="font-black text-secondary text-lg leading-tight">{selectedShipment.consignee_address?.name}</p>
                        <div className="space-y-2 mt-4">
                          <p className="text-xs text-gray-500 font-bold flex items-center gap-2">
                            <Phone size={12} className="text-blue-500" /> {selectedShipment.consignee_address?.phone}
                          </p>
                          <p className="text-xs text-gray-600 leading-relaxed flex items-start gap-2">
                            <MapPin size={12} className="text-blue-500 mt-0.5 shrink-0" /> 
                            <span>{selectedShipment.consignee_address?.address_line1 || selectedShipment.consignee_address?.address}, <span className="font-bold text-gray-400">{selectedShipment.consignee_address?.city}, {selectedShipment.consignee_address?.country}</span></span>
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Shipment Info Section */}
                  <div className="space-y-4">
                    <h4 className="text-[9px] font-black text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                      <div className="w-1 h-1 rounded-full bg-secondary" /> Package Details
                    </h4>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                      {[
                        { label: 'Weight', value: `${selectedShipment.actual_weight_kg} kg` },
                        { label: 'Length', value: `${selectedShipment.length_cm} cm` },
                        { label: 'Width', value: `${selectedShipment.width_cm} cm` },
                        { label: 'Height', value: `${selectedShipment.height_cm} cm` }
                      ].map((item, i) => (
                        <div key={i} className="bg-white border border-gray-100 p-4 rounded-xl text-center shadow-sm">
                          <p className="text-[8px] text-gray-300 uppercase font-black mb-1 tracking-widest">{item.label}</p>
                          <p className="text-lg font-black text-secondary">{item.value}</p>
                        </div>
                      ))}
                    </div>
                    
                    <div className="bg-gray-50 p-4 rounded-xl border border-gray-100 flex items-start gap-3">
                       <Tag className="text-secondary/20 w-4 h-4 mt-0.5 shrink-0" />
                       <div className="min-w-0">
                         <p className="text-[8px] font-black text-gray-400 uppercase tracking-widest mb-1">Description</p>
                         <p className="text-xs text-gray-700 font-medium leading-relaxed italic truncate md:whitespace-normal">
                           {selectedShipment.shipment_description || 'No specific description provided.'}
                         </p>
                       </div>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Modal Footer - COMPACT */}
            <div className="p-4 md:p-6 border-t border-gray-50 bg-gray-50/30 flex justify-end items-center">
              <button 
                onClick={() => setSelectedShipment(null)}
                className="px-6 py-2.5 rounded-xl font-black text-secondary hover:bg-secondary hover:text-white transition-all text-[10px] uppercase tracking-widest border border-gray-200 hover:border-secondary shadow-sm"
              >
                Close View
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ShipmentsPage;
