/* =============================================================================
   WandWeb Portal Views
   File: /portal/js/views.js
   Version: 30.1 (Force Fix)
   ============================================================================= */
console.log("Views.js v30.1 - Force Loaded"); // Debugging confirmation

const API_URL = '/api/portal_api.php'; 
const LOGO_URL = "https://wandweb.co/wp-content/uploads/2025/11/WEBP-LQ-Logo-with-text-mid-White.webp";

// Global helper: make URLs clickable and preserve formatting
window.formatTextWithLinks = (text) => {
    if (!text) return null;
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    const parts = text.split(urlRegex);
    return parts.map((part, i) => 
        urlRegex.test(part) ? 
        <a key={i} href={part} target="_blank" rel="noopener noreferrer" className="text-blue-200 underline hover:text-white break-all">{part}</a> 
        : part
    );
};

// --- HELPERS ---
const arrayMove = (arr, from, to) => { 
    const res = Array.from(arr); 
    const [removed] = res.splice(from, 1); 
    res.splice(to, 0, removed); 
    return res;
};

const ProjectCard = ({ project, role, setActiveProject, onDelete, onUpdateStatus, onAssignManager, partners }) => {
    const Icons = window.Icons;
    const isAdmin = role === 'admin';
    const isManager = role === 'admin' || role === 'partner';
    const isArchived = project.status === 'archived';

    // Dynamic classes for visual state
    const containerClasses = isArchived 
        ? "bg-slate-50 border-slate-200 opacity-60 grayscale cursor-not-allowed" 
        : "bg-white hover:border-[#2493a2] cursor-pointer shadow-sm hover:shadow-md";

    return (
        <div 
            className={`p-6 rounded-xl border transition-all group ${containerClasses}`} 
            onClick={()=> !isArchived && setActiveProject(project)}
        >
            <div className="flex justify-between items-start mb-4">
                <div>
                    <h4 className="font-bold text-slate-800">{project.title}</h4>
                    <p className="text-xs text-slate-500">{isManager ? (project.client_name || 'Unassigned') : 'Active Project'}</p>
                    {project.manager_name && <p className="text-xs text-[#2493a2] font-semibold mt-1">👤 Manager: {project.manager_name}</p>}
                </div>
                
                {/* TOP RIGHT ACTIONS (Admin Only) */}
                <div className="flex items-center gap-1">
                    <span className={`px-2 py-1 text-[10px] uppercase font-bold rounded mr-1 ${project.status==='active'?'bg-green-100 text-green-700':'bg-slate-100 text-slate-500'}`}>{project.status}</span>
                    
                    {isAdmin && (
                        <>
                            <button onClick={(e)=>{e.stopPropagation(); onAssignManager(project)}} className="text-slate-300 hover:text-teal-600 p-1.5 rounded-md hover:bg-teal-50 transition-colors" title="Assign Manager">
                                <Icons.Users size={16}/>
                            </button>
                            <button onClick={(e)=>{e.stopPropagation(); onUpdateStatus(project.id, 'archived')}} className="text-slate-300 hover:text-blue-600 p-1.5 rounded-md hover:bg-blue-50 transition-colors" title="Archive">
                                <Icons.Archive size={16}/>
                            </button>
                            <button onClick={(e)=>{e.stopPropagation(); onDelete(project.id)}} className="text-slate-300 hover:text-red-600 p-1.5 rounded-md hover:bg-red-50 transition-colors" title="Delete">
                                <Icons.Trash size={16}/>
                            </button>
                        </>
                    )}
                </div>
            </div>
            
            <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden mb-2">
                <div className={`h-full ${project.health_score < 40 ? 'bg-red-500' : 'bg-green-500'}`} style={{width: project.health_score + '%'}}></div>
            </div>

            {/* STATUS CONTROLS (Managers Only - Hidden if Archived) */}
            {isManager && !isArchived && (
                <div className="flex flex-wrap gap-2 mt-4 pt-4 border-t border-slate-100">
                    <button onClick={(e)=>{e.stopPropagation(); onUpdateStatus(project.id, 'active')}} className="text-[10px] bg-slate-50 px-2 py-1 rounded hover:bg-green-50 hover:text-green-600 border border-transparent hover:border-green-200 transition-colors">Active</button>
                    <button onClick={(e)=>{e.stopPropagation(); onUpdateStatus(project.id, 'stalled')}} className="text-[10px] bg-slate-50 px-2 py-1 rounded hover:bg-orange-50 hover:text-orange-600 border border-transparent hover:border-orange-200 transition-colors">Stall</button>
                    <button onClick={(e)=>{e.stopPropagation(); onUpdateStatus(project.id, 'complete')}} className="text-[10px] bg-slate-50 px-2 py-1 rounded hover:bg-blue-50 hover:text-blue-600 border border-transparent hover:border-blue-200 transition-colors">Done</button>
                </div>
            )}
            
            {isArchived && isAdmin && (
                <div className="mt-4 pt-4 border-t border-slate-200 text-center">
                    <button onClick={(e)=>{e.stopPropagation(); onUpdateStatus(project.id, 'active')}} className="text-xs font-bold text-blue-600 hover:underline">Restore Project</button>
                </div>
            )}
        </div>
    );
};

const TaskManager = ({ project, token, role, onClose }) => {
    const Icons = window.Icons;
    const [details, setDetails] = React.useState({ tasks: [], comments: [] });
    const [msg, setMsg] = React.useState("");
    const [newTask, setNewTask] = React.useState("");
    const [showFileUpload, setShowFileUpload] = React.useState(false);
    const [selectedFiles, setSelectedFiles] = React.useState([]); // Changed to array
    const [isUploading, setIsUploading] = React.useState(false);

    const load = async () => {
        const r = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_project_details', token, project_id: project.id }) });
        if (r.status === 'success') setDetails(r);
    };
    React.useEffect(() => { load(); }, [project.id]);

    const isManager = role === 'admin' || role === 'partner';

    const addTask = async () => { if (!newTask.trim()) return; await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'save_task', token, project_id: project.id, title: newTask }) }); setNewTask(""); load(); };
    
    const toggleTask = async (id, current) => { 
        // Optimistic update
        const updated = (details.tasks || []).map(t => { if (t.id === id) return { ...t, is_complete: !current }; return t; }); 
        setDetails({ ...details, tasks: updated }); 
        await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'toggle_task', token, id, is_complete: !current ? 1 : 0 }) }); 
        load(); 
    };

    const deleteTask = async (id) => {
        if(!confirm("Remove this task?")) return;
        await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'delete_task', token, task_id: id }) });
        load();
    };

    const sendComment = async (e) => { e.preventDefault(); if(!msg.trim()) return; await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'post_comment', token, project_id: project.id, message: msg, target_type: 'project', target_id: 0 }) }); setMsg(""); load(); };
    
    const handleFileUpload = async (e) => {
        e.preventDefault();
        if (selectedFiles.length === 0) return;
        
        setIsUploading(true);
        let uploadedCount = 0;

        // Upload files sequentially
        for (const file of selectedFiles) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('action', 'upload_file'); 
            formData.append('token', token);
            formData.append('project_id', project.id);
            
            try {
                const response = await fetch(API_URL, { method: 'POST', body: formData });
                const res = await response.json();
                if (res.status === 'success') uploadedCount++;
            } catch (error) {
                console.error("Upload failed for " + file.name);
            }
        }

        setIsUploading(false);
        if (uploadedCount > 0) {
            setShowFileUpload(false);
            setSelectedFiles([]);
            load(); // Reload chat to see the new file announcements
            if (uploadedCount < selectedFiles.length) alert(`Uploaded ${uploadedCount} of ${selectedFiles.length} files.`);
        } else {
            alert('Upload failed. Please try again.');
        }
    };
    
    const comments = (details.comments || []).filter(c => c.target_type === 'project');

    return (
        <div className="fixed inset-0 bg-[#2c3259]/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-6xl h-[85vh] flex overflow-hidden">
                <div className="flex-1 flex flex-col border-r border-slate-200">
                    <div className="p-6 border-b flex justify-between items-center">
                        <div><h2 className="text-xl font-bold text-[#2c3259]">{project.title}</h2></div>
                        <button onClick={onClose}><Icons.Close/></button>
                    </div>
                    <div className="flex-1 overflow-y-auto p-6">
                        <h3 className="font-bold text-slate-800 mb-4">Tasks</h3>
                        {isManager && (
                            <div className="flex gap-2 mb-4"><input className="flex-1 p-3 border rounded-lg text-sm" placeholder="New Task..." value={newTask} onChange={e=>setNewTask(e.target.value)} /><button onClick={addTask} className="bg-[#2c3259] text-white px-4 rounded-lg"><Icons.Plus/></button></div>
                        )}
                        <div className="space-y-2">
                            {(details.tasks || []).map(task => {
                                const isChecked = Number(task.is_complete) === 1;
                                return (
                                    <div key={task.id} className={`p-4 border rounded-xl flex items-center gap-3 group transition-colors ${isChecked ? 'bg-slate-50' : 'border-slate-200 hover:border-blue-300'}`}>
                                        {isManager && (
                                            <button 
                                                onClick={() => toggleTask(task.id, isChecked)} 
                                                className={`w-6 h-6 rounded-sm border flex items-center justify-center transition-colors flex-shrink-0 ${isChecked ? 'bg-green-500 border-green-500 text-white' : 'bg-white border-slate-300'}`}
                                            >
                                                {isChecked ? <Icons.Check size={14}/> : null} 
                                            </button>
                                        )}
                                        {!isManager && (
                                            <div className={`w-6 h-6 rounded-sm border flex items-center justify-center flex-shrink-0 ${isChecked ? 'bg-green-500 border-green-500 text-white' : 'bg-white border-slate-300'}`}>
                                                {isChecked ? <Icons.Check size={14}/> : null} 
                                            </div>
                                        )}
                                        <span className={`flex-1 ${isChecked ? 'line-through text-slate-400' : 'text-slate-700'}`}>{task.title}</span>
                                        {isManager && (
                                            <button onClick={() => deleteTask(task.id)} className="text-slate-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <Icons.Trash size={14}/>
                                            </button>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
                <div className="w-96 flex flex-col bg-slate-50">
                    <div className="p-4 border-b flex justify-between items-center">
                        <h3 className="font-bold text-slate-800">Project Chat</h3>
                        <button onClick={() => setShowFileUpload(!showFileUpload)} className="text-slate-500 hover:text-[#2493a2] p-1" title="Upload File">
                            <Icons.Paperclip size={18}/>
                        </button>
                    </div>
                    {showFileUpload && (
                        <div className="p-4 border-b bg-white animate-fade-in">
                            <form onSubmit={handleFileUpload} className="space-y-3">
                                <div className="space-y-2">
                                    <label className="text-xs font-bold text-slate-500 uppercase">Upload to Project Drive</label>
                                    <input 
                                        type="file" 
                                        multiple
                                        onChange={(e) => setSelectedFiles(Array.from(e.target.files))} 
                                        className="w-full text-xs file:mr-2 file:py-2 file:px-3 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-[#2c3259] file:text-white hover:file:bg-[#1d7a87] file:cursor-pointer border border-slate-200 rounded p-1"
                                        required
                                        disabled={isUploading}
                                    />
                                    {selectedFiles.length > 0 && (
                                        <div className="text-xs text-slate-600 p-2 bg-slate-50 rounded">
                                            <div className="font-bold mb-1">{selectedFiles.length} file{selectedFiles.length !== 1 ? 's' : ''} selected</div>
                                            <div className="max-h-20 overflow-y-auto space-y-1">
                                                {selectedFiles.map((f, i) => (
                                                    <div key={i} className="text-[10px] text-slate-400 truncate flex justify-between">
                                                        <span>• {f.name}</span>
                                                        <span>{(f.size/1024).toFixed(0)}KB</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                                <div className="flex gap-2">
                                    <button 
                                        type="submit" 
                                        disabled={isUploading || selectedFiles.length === 0}
                                        className={`flex-1 text-white p-2 rounded text-xs font-bold flex items-center justify-center gap-2 ${isUploading ? 'bg-slate-400 cursor-not-allowed' : 'bg-[#2493a2] hover:bg-[#1d7a87]'}`}
                                    >
                                        {isUploading ? <><Icons.Loader size={14} className="animate-spin"/> Uploading...</> : 'Upload Files'}
                                    </button>
                                    <button 
                                        type="button" 
                                        onClick={() => { setShowFileUpload(false); setSelectedFiles([]); }} 
                                        className="px-3 py-2 text-slate-600 text-xs border rounded hover:bg-slate-50"
                                        disabled={isUploading}
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}
                    <div className="flex-1 overflow-y-auto p-4 space-y-3">{comments.map(c => <div key={c.id} className="bg-white p-3 rounded-lg shadow-sm border text-sm"><p className="font-bold text-xs text-[#2c3259] mb-1">{c.author || c.full_name}</p><div className="whitespace-pre-wrap">{window.formatTextWithLinks(c.message)}</div></div>)}</div>
                    <form onSubmit={sendComment} className="p-4 border-t flex gap-2"><input className="flex-1 p-2 border rounded text-sm" value={msg} onChange={e=>setMsg(e.target.value)} placeholder="Send project message..."/><button className="bg-[#dba000] text-white p-2 rounded"><Icons.Send/></button></form>
                </div>
            </div>
        </div>
    );
};

const ServiceCard = ({ product, isAdmin, onBuy, onEdit, onDelete, onToggleVisibility }) => {
    const Icons = window.Icons;
    const [selectedPriceIdx, setSelectedPriceIdx] = React.useState(0);
    if (!product || !product.prices || product.prices.length === 0) return null;
    const price = product.prices[selectedPriceIdx] || product.prices[0];
    const isHidden = product.is_hidden;
    const actionLabel = price.interval !== 'one-time' ? 'Subscribe' : 'Buy';
    return (
        <div className={`bg-white p-6 rounded-xl border hover:border-[#dba000] relative group transition-all ${isHidden ? 'opacity-60 bg-slate-50' : ''}`}>
            {isAdmin && (<div className="absolute top-3 right-3 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity bg-white p-1 rounded shadow z-10"><button onClick={() => onToggleVisibility(product.id, isHidden)} className="text-slate-500 hover:text-[#2c3259]" title={isHidden ? "Show" : "Hide"}>{isHidden ? <Icons.EyeOff size={16}/> : <Icons.Eye size={16}/>}</button><button onClick={() => onEdit(product)} className="text-slate-400 hover:text-blue-500"><Icons.Edit size={16}/></button><button onClick={() => onDelete(product.id)} className="text-slate-400 hover:text-red-500"><Icons.Trash size={16}/></button></div>)}
            {product.image && <img src={product.image} alt={product.name} className="w-full h-32 object-cover rounded-lg mb-4" />}
            <h3 className="font-bold text-lg text-[#2c3259]">{product.name}</h3><p className="text-sm text-slate-500 mb-4 line-clamp-2">{product.description}</p>
            {product.prices.length > 1 && (<div className="flex gap-2 mb-4 overflow-x-auto pb-2">{product.prices.map((p, idx) => (<button key={p.id} onClick={() => setSelectedPriceIdx(idx)} className={`px-3 py-1 rounded-full text-xs font-bold whitespace-nowrap transition-colors border ${selectedPriceIdx === idx ? 'bg-[#2c3259] text-white border-[#2c3259]' : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'}`}>{p.interval === 'one-time' ? 'Once' : p.interval}</button>))}</div>)}
            <div className="flex justify-between items-center mt-auto pt-4 border-t border-slate-100"><div><span className="font-bold text-xl">${price.amount}</span><span className="text-xs text-slate-400 font-normal ml-1">{price.currency} {price.interval!=='one-time'?'/'+price.interval:''}</span></div>{!isAdmin && <button onClick={() => onBuy(price.id, price.interval)} className="bg-[#dba000] hover:bg-yellow-600 text-white px-4 py-2 rounded-lg font-bold text-sm transition-colors shadow-sm">{actionLabel}</button>}</div>
        </div>
    );
};

const ServiceSortModal = ({ services, onClose, onSave }) => {
    const Icons = window.Icons;
    const [items, setItems] = React.useState(Object.keys(services || {}).sort().map((cat, i) => ({ id: `cat_${cat}`, label: cat, type: 'category', index: i, children: (services[cat] || []).map((p, pi) => ({ id: p.id, label: p.name, type: 'product', index: pi })) })));
    const [draggedItem, setDraggedItem] = React.useState(null); 
    const [draggedParent, setDraggedParent] = React.useState(null);
    
    const onDragStart = (e, item, parentIdx = null) => { setDraggedItem(item); setDraggedParent(parentIdx); e.dataTransfer.effectAllowed = "move"; };
    const onDragOver = (e, index, parentIdx = null) => { 
        e.preventDefault(); 
        if (!draggedItem) return; 
        if (draggedItem.type === 'category' && parentIdx === null && draggedParent === null) { 
            if (draggedItem.index === index) return; 
            const newItems = arrayMove(items, draggedItem.index, index); 
            newItems.forEach((item, idx) => item.index = idx); 
            setItems(newItems); setDraggedItem({ ...draggedItem, index }); 
        } 
        if (draggedItem.type === 'product' && parentIdx !== null && parentIdx === draggedParent) { 
            if (draggedItem.index === index) return; 
            const newItems = [...items]; 
            const category = newItems[parentIdx]; 
            category.children = arrayMove(category.children, draggedItem.index, index); 
            category.children.forEach((item, idx) => item.index = idx); 
            setItems(newItems); setDraggedItem({ ...draggedItem, index }); 
        }
    };
    const handleSave = () => { 
        const orderList = []; 
        items.forEach((cat, cIdx) => { 
            orderList.push({ key: cat.id, index: cIdx }); 
            cat.children.forEach((prod, pIdx) => { orderList.push({ key: prod.id, index: pIdx }); }); 
        }); 
        onSave(orderList); 
    };
    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
            <div className="bg-white rounded-xl w-full max-w-lg h-[80vh] flex flex-col">
                <div className="p-4 border-b flex justify-between items-center"><h3 className="font-bold text-lg">Arrange Services</h3><button onClick={onClose}><Icons.Close/></button></div>
                <div className="flex-1 overflow-y-auto p-4 space-y-2 bg-slate-50">{items.map((cat, cIdx) => (<div key={cat.id} draggable onDragStart={(e) => onDragStart(e, cat)} onDragOver={(e) => onDragOver(e, cIdx)} className="bg-white border rounded-lg overflow-hidden shadow-sm"><div className="p-3 bg-slate-100 font-bold text-slate-700 cursor-move flex gap-2 items-center"><Icons.Menu size={16} className="text-slate-400"/> {cat.label}</div><div className="p-2 space-y-1">{cat.children.map((prod, pIdx) => (<div key={prod.id} draggable onDragStart={(e) => { e.stopPropagation(); onDragStart(e, prod, cIdx); }} onDragOver={(e) => { e.stopPropagation(); onDragOver(e, pIdx, cIdx); }} className="p-2 bg-white border rounded flex gap-2 items-center text-sm cursor-move hover:bg-slate-50"><Icons.Menu size={14} className="text-slate-300"/> {prod.label}</div>))}</div></div>))}</div>
                <div className="p-4 border-t"><button onClick={handleSave} className="w-full bg-[#2c3259] text-white p-3 rounded-lg font-bold">Save Order</button></div>
            </div>
        </div>
    );
};

// --- GLOBAL VIEWS (Attached to Window for App.js) ---

window.ClientAdminModal = ({ token, client, onClose, onUpdate }) => {
    const Icons = window.Icons;
    const [tab, setTab] = React.useState('profile');
    const [details, setDetails] = React.useState(null);
    const [loading, setLoading] = React.useState(true);
    const [allClients, setAllClients] = React.useState([]); // For assignment dropdown
    const [assignId, setAssignId] = React.useState(""); // For managed clients assignment

    React.useEffect(() => {
        setLoading(true);
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_client_details', token, client_id: client.id }) })
            .then(res => { if (res.status === 'success') setDetails(res); })
            .finally(() => setLoading(false));
    }, [client]);

    const handleSaveProfile = async (e) => {
        e.preventDefault();
        const f = new FormData(e.target);
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'update_client', token, client_id: client.id, full_name: f.get('full_name'), business_name: f.get('business_name'), email: f.get('email'), phone: f.get('phone'), status: f.get('status') }) });
        if (res.status === 'success') { onUpdate(); onClose(); } else alert(res.message);
    };

    const handlePromote = async () => {
        const c = details?.client || client;
        const newRole = c.role === 'partner' ? 'client' : 'partner';
        if (confirm(`Change role to ${newRole.toUpperCase()}?`)) {
            const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'update_user_role', token, client_id: client.id, role: newRole }) });
            if (res.status === 'success') { onUpdate(); onClose(); }
        }
    };

    const handleAssign = async (targetId) => {
        if (!targetId) return;
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'assign_client_partner', token, partner_id: client.id, client_id: targetId }) });
        if (res.status === 'success') {
            window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_client_details', token, client_id: client.id }) }).then(res => setDetails(res));
        }
    };

    const handleUnassign = async (targetId) => {
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'assign_client_partner', token, partner_id: client.id, client_id: targetId, action_type: 'remove' }) });
        if (res.status === 'success') {
             window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_client_details', token, client_id: client.id }) }).then(res => setDetails(res));
        }
    };

    // Fetch all clients unconditionally - the component always has this useEffect
    React.useEffect(() => {
        const c = details?.client || client;
        if (details && c.role === 'partner') {
            window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) })
                .then(res => setAllClients(res.clients || []));
        }
    }, [token, details, client]);

    if (!details && loading) return <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center"><div className="bg-white p-8 rounded-xl"><Icons.Loader/></div></div>;

    const c = details?.client || client;
    const invs = details?.invoices || [];
    const subs = details?.subscriptions || [];
    const projs = details?.projects || [];
    const managed = details?.managed_clients || [];

    return (
        <div className="fixed inset-0 bg-[#2c3259]/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-white w-full max-w-4xl h-[90vh] rounded-2xl shadow-2xl flex flex-col overflow-hidden animate-fade-in">
                <div className="bg-slate-50 border-b p-6 flex justify-between items-start">
                    <div>
                        <div className="flex items-center gap-2">
                            <h2 className="text-2xl font-bold text-[#2c3259]">{c.full_name}</h2>
                            {c.role === 'partner' && <span className="bg-[#dba000] text-white text-[10px] px-2 py-1 rounded uppercase font-bold">Partner</span>}
                        </div>
                        <div className="text-sm text-slate-500 flex gap-2 mt-1"><span>{c.business_name}</span><span>•</span><span className="font-mono text-slate-400">{c.email}</span></div>
                    </div>
                    <button onClick={onClose} className="p-2 hover:bg-slate-200 rounded-full transition-colors"><Icons.Close/></button>
                </div>
                
                <div className="flex border-b px-6 gap-6">
                    {['profile', 'financials', 'projects', c.role === 'partner' ? 'managed clients' : null].filter(Boolean).map(t => (
                        <button key={t} onClick={()=>setTab(t)} className={`py-4 text-sm font-bold border-b-2 capitalize transition-colors ${tab===t ? 'border-[#2c3259] text-[#2c3259]' : 'border-transparent text-slate-400 hover:text-slate-600'}`}>{t}</button>
                    ))}
                </div>

                <div className="flex-1 overflow-y-auto p-8 bg-slate-50/50">
                    {tab === 'profile' && (
                        <form onSubmit={handleSaveProfile} className="max-w-lg mx-auto space-y-6">
                            <div className="grid grid-cols-2 gap-4"><div><label className="block text-xs font-bold text-slate-500 mb-1">Full Name</label><input name="full_name" defaultValue={c.full_name} className="w-full p-2 border rounded" required /></div><div><label className="block text-xs font-bold text-slate-500 mb-1">Status</label><select name="status" defaultValue={c.status} className="w-full p-2 border rounded"><option value="active">Active</option><option value="pending_invite">Pending Invite</option><option value="inactive">Inactive</option></select></div></div><div><label className="block text-xs font-bold text-slate-500 mb-1">Email</label><input name="email" defaultValue={c.email} className="w-full p-2 border rounded" required /></div><div className="grid grid-cols-2 gap-4"><div><label className="block text-xs font-bold text-slate-500 mb-1">Business</label><input name="business_name" defaultValue={c.business_name} className="w-full p-2 border rounded" /></div><div><label className="block text-xs font-bold text-slate-500 mb-1">Phone</label><input name="phone" defaultValue={window.formatPhone(c.phone)} className="w-full p-2 border rounded" /></div></div>
                            <button className="w-full bg-[#2c3259] text-white p-3 rounded-lg font-bold shadow-lg hover:opacity-90">Save Changes</button>
                            <div className="pt-6 border-t mt-6">
                                <button type="button" onClick={handlePromote} className="text-xs text-blue-600 underline">
                                    {c.role === 'partner' ? 'Demote to Standard Client' : 'Promote to Partner (Manager)'}
                                </button>
                            </div>
                        </form>
                    )}
                    {tab === 'financials' && (<div className="space-y-8"><div><h3 className="text-lg font-bold text-[#2c3259] mb-4">Active Subscriptions</h3>{subs.length === 0 ? <p className="text-sm text-slate-400 italic">No active subscriptions found.</p> : <div className="bg-white border rounded-lg overflow-hidden"><table className="w-full text-sm text-left"><thead className="bg-slate-50 border-b"><tr><th className="p-3">Plan</th><th className="p-3">Amount</th><th className="p-3">Next Bill</th></tr></thead><tbody>{subs.map(s => <tr key={s.id} className="border-b"><td className="p-3 font-bold">{s.plan}</td><td className="p-3">${s.amount}/{s.interval}</td><td className="p-3 text-slate-500">{s.next_bill}</td></tr>)}</tbody></table></div>}</div><div><h3 className="text-lg font-bold text-[#2c3259] mb-4">Invoice History</h3>{invs.length === 0 ? <p className="text-sm text-slate-400 italic">No invoices found.</p> : <div className="bg-white border rounded-lg overflow-hidden"><table className="w-full text-sm text-left"><thead className="bg-slate-50 border-b"><tr><th className="p-3">Date</th><th className="p-3">#</th><th className="p-3">Amount</th><th className="p-3">Status</th><th className="p-3 text-right">PDF</th></tr></thead><tbody>{invs.map(i => <tr key={i.id} className="border-b"><td className="p-3 text-slate-500">{i.date}</td><td className="p-3 font-mono text-xs">{i.number}</td><td className="p-3 font-bold">${i.amount}</td><td className="p-3"><span className={`px-2 py-1 rounded text-[10px] uppercase font-bold ${i.status==='paid'?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}`}>{i.status}</span></td><td className="p-3 text-right"><a href={i.pdf} target="_blank" className="text-blue-600 hover:underline">Download</a></td></tr>)}</tbody></table></div>}</div></div>)}
                    {tab === 'projects' && (<div><div className="flex justify-between items-center mb-4"><h3 className="text-lg font-bold text-[#2c3259]">Client Projects</h3></div>{projs.length === 0 ? <div className="p-8 text-center border-2 border-dashed rounded-lg text-slate-400">No projects found.</div> : <div className="grid grid-cols-1 md:grid-cols-2 gap-4">{projs.map(p => (<div key={p.id} className="bg-white p-4 rounded-lg border shadow-sm"><div className="flex justify-between mb-2"><h4 className="font-bold">{p.title}</h4><span className="text-xs bg-slate-100 px-2 py-1 rounded uppercase font-bold">{p.status}</span></div><div className="w-full bg-slate-200 h-2 rounded-full overflow-hidden"><div className="h-full bg-[#dba000]" style={{width: p.health_score + '%'}}></div></div></div>))}</div>}</div>)}
                    {tab === 'managed clients' && (
                        <div>
                            <div className="bg-blue-50 border border-blue-200 p-4 rounded mb-6 text-sm text-blue-800">
                                This user is a <strong>Partner</strong>. They can view projects and tickets for the clients listed below.
                            </div>
                            <div className="mb-6 flex gap-2">
                                <select 
                                    value={assignId} 
                                    onChange={(e) => setAssignId(e.target.value)} 
                                    className="flex-1 p-2 border rounded"
                                >
                                    <option value="">Select a client to assign...</option>
                                    {allClients.filter(ac => ac.id !== c.id && !managed.find(m => m.id === ac.id)).map(ac => (
                                        <option key={ac.id} value={ac.id}>{ac.full_name} ({ac.business_name})</option>
                                    ))}
                                </select>
                                <button 
                                    onClick={() => { handleAssign(assignId); setAssignId(""); }} 
                                    disabled={!assignId}
                                    className="bg-[#2c3259] text-white px-4 py-2 rounded font-bold disabled:opacity-50"
                                >
                                    Assign
                                </button>
                            </div>
                            <div className="bg-white border rounded overflow-hidden">
                                {managed.length === 0 ? <div className="p-6 text-center text-slate-400">No clients assigned yet.</div> : (
                                    <table className="w-full text-sm text-left">
                                        <thead className="bg-slate-50 border-b"><tr><th className="p-3">Client</th><th className="p-3">Business</th><th className="p-3 text-right">Action</th></tr></thead>
                                        <tbody>
                                            {managed.map(m => (
                                                <tr key={m.id} className="border-b">
                                                    <td className="p-3 font-bold">{m.full_name}</td>
                                                    <td className="p-3 text-slate-500">{m.business_name}</td>
                                                    <td className="p-3 text-right"><button onClick={()=>handleUnassign(m.id)} className="text-red-500 hover:underline text-xs">Remove</button></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

window.ClientsView = ({ token, role }) => { 
    const Icons = window.Icons;
    const [clients, setClients] = React.useState([]); 
    const [loading, setLoading] = React.useState(true); 
    const [show, setShow] = React.useState(false); 
    const [selectedClient, setSelectedClient] = React.useState(null);
    const [tab, setTab] = React.useState('manual');
    const [searchValue, setSearchValue] = React.useState("");
    const [filterValue, setFilterValue] = React.useState("all");
    const [sortOrder, setSortOrder] = React.useState("newest");

    if(role!=='admin') return <div className="p-10 text-center">Access Denied</div>; 
    const fetchData = () => { window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) }).then(res => { if(res && res.status==='success') setClients(res.clients||[]); }).finally(()=>setLoading(false)); }; 
    React.useEffect(() => { fetchData(); }, [token]); 
    
    const handleAddClient = async (e) => { 
        e.preventDefault(); 
        const f = new FormData(e.target); 
        if (tab === 'invite') { 
            const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'send_onboarding_link', token, email: f.get('email') }) }); 
            if(res.status==='success') { setShow(false); alert("Invite Sent"); } else alert(res.message); 
        } else { 
            const res = await window.safeFetch(API_URL, { 
                method: 'POST', 
                body: JSON.stringify({ 
                    action: 'create_client', 
                    token, 
                    email: f.get('email'), 
                    full_name: f.get('full_name'), 
                    business_name: f.get('business_name'), 
                    phone: f.get('phone'), 
                    send_invite: f.get('send_invite') === 'on' 
                }) 
            }); 
            if(res.status==='success') { setShow(false); fetchData(); } else alert(res.message); 
        } 
    }; 
    if(loading) return <div className="p-8 text-center"><Icons.Loader/></div>; 

    // SORT & FILTER LOGIC
    const filteredClients = clients.filter(c => {
        const searchMatch = !searchValue || c.full_name.toLowerCase().includes(searchValue.toLowerCase()) || c.email.toLowerCase().includes(searchValue.toLowerCase());
        const filterMatch = filterValue === 'all' || c.status === filterValue;
        return searchMatch && filterMatch;
    }).sort((a, b) => {
        if (sortOrder === 'newest') return new Date(b.created_at) - new Date(a.created_at);
        if (sortOrder === 'oldest') return new Date(a.created_at) - new Date(b.created_at);
        if (sortOrder === 'alpha_asc') return a.full_name.localeCompare(b.full_name);
        if (sortOrder === 'alpha_desc') return b.full_name.localeCompare(a.full_name);
        return 0;
    });

    return (<div className="space-y-6 animate-fade-in"><div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4"><h2 className="text-2xl font-bold text-[#2c3259]">Clients</h2><button onClick={()=>setShow(true)} className="bg-[#2493a2] text-white px-4 py-2 rounded font-bold flex items-center gap-2"><Icons.Plus size={16}/> Add Client</button></div>
    
    <window.FilterSortToolbar 
        filterOptions={[{value:'all', label:'All Status'}, {value:'active', label:'Active'}, {value:'pending_invite', label:'Pending Invite'}, {value:'inactive', label:'Inactive'}]}
        filterValue={filterValue} onFilterChange={setFilterValue}
        sortOrder={sortOrder} onSortToggle={()=>setSortOrder(prev => {
            if(prev === 'newest') return 'oldest';
            if(prev === 'oldest') return 'alpha_asc';
            if(prev === 'alpha_asc') return 'alpha_desc';
            return 'newest';
        })}
        searchValue={searchValue} onSearchChange={setSearchValue}
    />

    <div className="bg-white rounded border overflow-hidden"><table className="w-full text-sm text-left"><thead className="bg-slate-50 border-b"><tr><th className="p-3">Name</th><th className="p-3">Email</th><th className="p-3">Status</th><th className="p-3 text-right">Manage</th></tr></thead><tbody>{filteredClients.length === 0 ? <tr><td colSpan="4" className="p-4 text-center text-slate-400">No clients found.</td></tr> : filteredClients.map(c=>(<tr key={c.id} className="border-b hover:bg-slate-50 cursor-pointer" onClick={()=>setSelectedClient(c)}><td className="p-3"><div className="font-bold text-slate-800">{c.full_name}</div><div className="text-xs text-slate-500">{c.business_name}</div></td><td className="p-3"><div>{c.email}</div><div className="text-xs text-slate-400">{window.formatPhone(c.phone)}</div></td><td className="p-3"><span className={`px-2 py-1 rounded text-xs uppercase font-bold border ${c.status==='active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200'}`}>{c.status.replace('_', ' ')}</span></td><td className="p-3 text-right"><button className="text-blue-600 hover:text-blue-800 font-bold text-xs" onClick={(e) => { e.stopPropagation(); setSelectedClient(c); }}>View</button></td></tr>))}</tbody></table></div>{show && <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50"><div className="bg-white p-8 rounded-xl w-full max-w-md relative"><button onClick={()=>setShow(false)} className="absolute top-4 right-4"><Icons.Close/></button><h3 className="font-bold text-xl mb-4">Add New Client</h3><div className="flex gap-4 mb-4 border-b"><button onClick={()=>setTab('manual')} className={`pb-2 text-sm font-bold ${tab==='manual'?'border-b-2 border-[#2493a2] text-[#2c3259]':'text-slate-400'}`}>Manual Entry</button><button onClick={()=>setTab('invite')} className={`pb-2 text-sm font-bold ${tab==='invite'?'border-b-2 border-[#2493a2] text-[#2c3259]':'text-slate-400'}`}>Send Link Only</button></div><form onSubmit={handleAddClient} className="space-y-4"><input name="email" type="email" placeholder="Email Address *" className="w-full p-2 border rounded" required/>{tab === 'manual' && <><input name="full_name" type="text" placeholder="Full Name *" className="w-full p-2 border rounded" required/><input name="business_name" type="text" placeholder="Business Name" className="w-full p-2 border rounded"/><input name="phone" type="text" placeholder="Phone" className="w-full p-2 border rounded"/><div className="flex items-center gap-2"><input type="checkbox" name="send_invite" id="send_invite" /><label htmlFor="send_invite" className="text-sm text-slate-600">Send Invite Email Now?</label></div></>}<button className="w-full bg-[#2493a2] text-white p-2 rounded font-bold">{tab==='manual'?'Create Client':'Send Onboarding Link'}</button></form></div></div>}{selectedClient && <window.ClientAdminModal token={token} client={selectedClient} onClose={()=>setSelectedClient(null)} onUpdate={fetchData} />}</div>); 
};

window.SettingsView = ({ token, role }) => {
    const Icons = window.Icons;
    const [activeTab, setActiveTab] = React.useState('admin_controls'); 
    const [users, setUsers] = React.useState([]);
    const [rawUsers, setRawUsers] = React.useState([]); // For Audit
    const [partners, setPartners] = React.useState([]);
    const [clients, setClients] = React.useState([]);
    const [loading, setLoading] = React.useState(false);
    const [logs, setLogs] = React.useState([]);
    const [sysLogs, setSysLogs] = React.useState([]);
    const [showAssignModal, setShowAssignModal] = React.useState(false);
    const [selectedPartner, setSelectedPartner] = React.useState(null);

    if (role !== 'admin') return <div className="p-10 text-center text-slate-500">Access Restricted</div>;

    // Auto-navigate to partners tab if user was just fixed
    React.useEffect(() => {
        const fixed = sessionStorage.getItem('_justFixedUser');
        if (fixed) {
            setActiveTab('partners');
            sessionStorage.removeItem('_justFixedUser');
            setTimeout(() => {
                const msg = JSON.parse(fixed);
                alert(`✓ User #${msg.id} is now a ${msg.role.toUpperCase()}. Check Partners tab.`);
            }, 100);
        }
    }, []);

    React.useEffect(() => {
        if (activeTab === 'users') fetchUsers();
        if (activeTab === 'partners') fetchPartners();
        if (activeTab === 'audit') fetchAudit();
        if (activeTab === 'logs') fetchLogs();
    }, [activeTab]);

    const fetchLogs = async () => {
        try {
            const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_system_logs', token }) });
            console.log('fetchLogs response:', res);
            if (res && res.status === 'success') {
                setSysLogs(res.logs || []);
                console.log('Logs updated:', res.logs?.length || 0);
            } else {
                console.error('fetchLogs failed:', res);
                setSysLogs([]);
            }
        } catch (error) {
            console.error('fetchLogs error:', error);
            setSysLogs([]);
        }
    };

    const fetchUsers = async () => {
        setLoading(true);
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) });
        if (res.status === 'success') setUsers(res.clients || []);
        setLoading(false);
    };

    const fetchAudit = async () => {
        setLoading(true);
        // Force fresh fetch with cache-busting timestamp
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_all_users', token, _t: Date.now() }) });
        if (res.status === 'success') {
            setRawUsers(res.users || []);
            if(res.users && res.users.length === 0) {
                console.warn('⚠️ Audit returned 0 users. Check database connection.');
            }
        } else {
            alert('Failed to fetch audit data: ' + res.message);
        }
        setLoading(false);
    };

    const fetchPartners = async () => {
        setLoading(true);
        const pRes = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_partners', token }) });
        if (pRes.status === 'success') setPartners(pRes.partners || []);
        const cRes = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) });
        if (cRes.status === 'success') setClients((cRes.clients || []).filter(c => c.role === 'client'));
        setLoading(false);
    };

    const handleRoleChange = async (userId, newRole) => {
        if(!confirm(`Change user role to ${newRole.toUpperCase()}?`)) return;
        const res = await window.safeFetch(API_URL, { 
            method: 'POST', 
            body: JSON.stringify({ action: 'update_user_role', token, client_id: userId, role: newRole }) 
        });
        if (res.status === 'success') fetchUsers(); else alert(res.message);
    };
    
    const handleForceFix = async (userId, role, status) => {
        const btn = document.activeElement;
        const originalText = btn.innerText;
        btn.innerText = "...";
        btn.disabled = true;

        // Call the safer backend
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'fix_user_account', token, target_user_id: userId, role, status }) });
        
        if (res.status === 'success') { 
            alert("Account recovered successfully. Reloading to apply changes...");
            // Store the target role/status so we can verify after reload
            sessionStorage.setItem('_justFixedUser', JSON.stringify({id: userId, role, status, timestamp: Date.now()}));
            // Force hard reload to ensure all lists (Partners/Clients) are rebuilt from scratch
            setTimeout(() => window.location.reload(), 100); 
        } else {
            alert("Error: " + (res.message || 'Action failed'));
            btn.innerText = originalText;
            btn.disabled = false;
        }
    };

    const handleAssignClient = async (clientId) => {
        if (!selectedPartner) return;
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'assign_partner', token, partner_id: selectedPartner.id, client_id: clientId }) });
        if (res.status === 'success') { setShowAssignModal(false); alert('Client assigned'); } else { alert(res.message); }
    };
    
    const handleRecalculateAll = async () => { setLogs(prev => ["Triggering project health check...", ...prev]); const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_projects', token }) }); if(res.projects) { setLogs(prev => [`Checked ${res.projects.length} projects. Syncing displays...`, ...prev]); window.dispatchEvent(new CustomEvent('switch_view', { detail: 'projects' })); setTimeout(() => window.dispatchEvent(new CustomEvent('switch_view', { detail: 'settings' })), 100); } };
    const handleMasterSync = async () => { setLoading(true); setLogs(prev => ["[START] Master Sync...", ...prev]); try { await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'import_crm_clients', token }) }); setLogs(prev => ["[CRM] Done", ...prev]); await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'import_stripe_clients', token }) }); setLogs(prev => ["[STRIPE] Done", ...prev]); } catch (e) { setLogs(prev => ["[ERROR] Sync Failed", ...prev]); } setLoading(false); };

    return (
        <div className="space-y-6 animate-fade-in">
            <div className="flex gap-4 border-b border-slate-200 pb-1 overflow-x-auto">
                {['admin_controls', 'users', 'partners', 'audit', 'logs'].map(t => {
                    const tabLabel = t === 'logs' ? 'Log/Debugging' : t.replace('_', ' ');
                    return <button key={t} onClick={() => setActiveTab(t)} className={`px-4 py-2 text-sm font-bold capitalize whitespace-nowrap ${activeTab === t ? 'text-[#2c3259] border-b-2 border-[#2c3259]' : 'text-slate-400'}`}>{tabLabel}</button>;
                })}
            </div>

            {activeTab === 'audit' && (
                <div className="bg-red-50 border border-red-200 rounded-xl overflow-hidden">
                    <div className="p-4 bg-red-100 text-red-900 text-sm font-bold flex justify-between items-center">
                        <span>⚠️ Database Audit (Recovery Mode) - Total Records: {rawUsers.length}</span>
                        <button onClick={fetchAudit} disabled={loading} className="bg-red-700 hover:bg-red-800 text-white px-3 py-1 rounded text-xs font-bold">
                            {loading ? 'Refreshing...' : 'Refresh'}
                        </button>
                    </div>
                    {rawUsers.length === 0 && (
                        <div className="p-4 text-center text-red-700 font-bold">
                            ✓ No undefined users. Database is clean!
                        </div>
                    )}
                    {rawUsers.length > 0 && (
                    <table className="w-full text-xs text-left">
                        <thead className="bg-white border-b"><tr><th className="p-2">ID</th><th className="p-2">Name/Email</th><th className="p-2">Raw Role</th><th className="p-2">Raw Status</th><th className="p-2 text-right">Force Fix</th></tr></thead>
                        <tbody>
                            {rawUsers.map(u => (
                                <tr key={u.id} className="border-b bg-white">
                                    <td className="p-2 font-mono text-slate-400">{u.id}</td>
                                    <td className="p-2">
                                        <div className="font-bold">{u.full_name}</div>
                                        <div className="text-slate-500">{u.email}</div>
                                    </td>
                                    <td className="p-2 font-mono text-red-600">{u.role}</td>
                                    <td className="p-2 font-mono">{u.status}</td>
                                    <td className="p-2 text-right flex gap-1 justify-end">
                                        <button onClick={()=>handleForceFix(u.id, 'partner', 'active')} className="bg-slate-800 text-white px-2 py-1 rounded hover:bg-black">Make Partner</button>
                                        <button onClick={()=>handleForceFix(u.id, 'client', 'active')} className="bg-slate-200 text-slate-700 px-2 py-1 rounded hover:bg-slate-300">Make Client</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    )}
                </div>
            )}

            {activeTab === 'admin_controls' && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="bg-white p-6 rounded-xl border shadow-sm">
                        <h3 className="text-lg font-bold text-[#2c3259] mb-2 flex items-center gap-2"><Icons.Activity size={20}/> Project Health</h3>
                        <button onClick={handleRecalculateAll} className="bg-slate-100 text-slate-700 px-4 py-2 rounded font-bold text-xs hover:bg-slate-200 w-full text-left">Recalculate Metrics</button>
                    </div>
                    <div className="bg-white p-6 rounded-xl border shadow-sm">
                        <h3 className="text-lg font-bold text-[#2c3259] mb-2 flex items-center gap-2"><Icons.Cloud size={20}/> External Data</h3>
                        <button onClick={handleMasterSync} disabled={loading} className="bg-[#2c3259] text-white px-4 py-2 rounded font-bold text-xs w-full text-left flex justify-between items-center">{loading ? 'Syncing...' : 'Run Master Sync'} <Icons.ArrowDown size={14}/></button>
                    </div>
                    {logs.length > 0 && <div className="col-span-2 bg-slate-900 text-green-400 p-4 rounded-lg font-mono text-xs max-h-40 overflow-y-auto">{logs.map((l,i)=><div key={i}>{l}</div>)}</div>}
                </div>
            )}

            {activeTab === 'users' && (
                <div className="bg-white rounded border overflow-hidden">
                    <table className="w-full text-sm text-left">
                        <thead className="bg-slate-50 border-b"><tr><th className="p-3">User</th><th className="p-3">Role</th><th className="p-3 text-right">Actions</th></tr></thead>
                        <tbody>
                            {users.map(u => (
                                <tr key={u.id} className="border-b">
                                    <td className="p-3">
                                        <div className="font-bold text-[#2c3259]">{u.full_name}</div>
                                        <div className="text-xs text-slate-400">{u.email}</div>
                                    </td>
                                    <td className="p-3"><span className="bg-slate-100 px-2 py-1 rounded text-xs uppercase font-bold">{u.role}</span></td>
                                    <td className="p-3 text-right">
                                        <select onChange={(e)=>handleRoleChange(u.id, e.target.value)} value={u.role} className="p-1 border rounded text-xs">
                                            <option value="client">Client</option>
                                            <option value="partner">Partner</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {activeTab === 'partners' && (
                <div className="space-y-6">
                    <div className="bg-white rounded border overflow-hidden">
                        <div className="p-4 border-b bg-slate-50 font-bold">Partner List</div>
                        <table className="w-full text-sm text-left">
                            <thead className="bg-slate-50 border-b"><tr><th className="p-3">Partner</th><th className="p-3">Contact</th><th className="p-3 text-right">Actions</th></tr></thead>
                            <tbody>
                                {partners.map(p => (
                                    <tr key={p.id} className="border-b">
                                        <td className="p-3"><div className="font-bold text-[#2c3259]">{p.full_name || 'Unnamed'}</div></td>
                                        <td className="p-3"><div className="text-xs text-slate-600">{p.email}</div></td>
                                        <td className="p-3 text-right"><button onClick={() => { setSelectedPartner(p); setShowAssignModal(true); }} className="bg-[#2493a2] text-white px-3 py-1 rounded text-xs font-bold hover:bg-[#1e7e8b]">Assign Client</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {showAssignModal && selectedPartner && (
                <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-xl w-full max-w-md shadow-2xl">
                        <div className="p-6 border-b flex justify-between items-center"><h3 className="font-bold text-lg">Assign Client to {selectedPartner.full_name}</h3><button onClick={() => setShowAssignModal(false)}><Icons.Close/></button></div>
                        <div className="p-6"><p className="text-sm text-slate-600 mb-4">Select a client to assign:</p><div className="space-y-2 max-h-80 overflow-y-auto">{clients.map(c => (<button key={c.id} onClick={() => handleAssignClient(c.id)} className="w-full text-left p-3 border rounded hover:bg-slate-50 transition-colors"><div className="font-bold text-[#2c3259]">{c.full_name}</div><div className="text-xs text-slate-400">{c.email}</div></button>))}</div></div>
                    </div>
                </div>
            )}

            {activeTab === 'logs' && <window.StandaloneDebugPanel token={token} />}
        </div>
    );
};

window.FilesView = ({ token, role }) => { 
    const Icons = window.Icons;
    const [files, setFiles] = React.useState([]); 
    const [loading, setLoading] = React.useState(true); 
    const [show, setShow] = React.useState(false); 
    const [clients, setClients] = React.useState([]); 
    const isAdmin = role === 'admin'; 
    
    const fetchData = () => { 
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_files', token }) })
            .then(res => { if(res.status==='success') setFiles(res.files||[]); })
            .finally(()=>setLoading(false)); 
    };
    
    React.useEffect(() => { 
        fetchData(); 
        if(isAdmin) window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) }).then(res => setClients(res.clients || []));
    }, [token]); 

    const handleUpload = async (e) => { 
        e.preventDefault(); 
        const btn = e.target.querySelector('button');
        btn.innerText = "Uploading to Drive..."; btn.disabled = true;
        
        const f = new FormData(e.target); 
        f.append('action', 'upload_file'); f.append('token', token); 
        
        try {
            const r = await fetch(API_URL, { method: 'POST', body: f });
            const d = await r.json();
            if(d.status==='success') { setShow(false); fetchData(); } else alert(d.message);
        } catch(e) { alert("Upload Failed"); }
        btn.innerText = "Upload Now"; btn.disabled = false;
    };

    const handleDelete = async (id) => {
        if(!confirm("Permanently delete from Drive & Portal?")) return;
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'delete_file', token, file_id: id }) });
        if(res.status === 'success') fetchData(); else alert(res.message);
    };

    if(loading) return <div className="p-8 text-center"><Icons.Loader/></div>;    

    return (
        <div className="space-y-6 animate-fade-in">
            <div className="flex justify-between items-center">
                <h2 className="text-2xl font-bold text-[#2c3259]">Files & Assets</h2>
                <button onClick={()=>setShow(true)} className="bg-[#2c3259] text-white px-4 py-2 rounded font-bold text-sm flex items-center gap-2"><Icons.Upload size={16}/> Upload File</button>
            </div>
            <div className="bg-white rounded-xl border shadow-sm overflow-hidden">
                <table className="w-full text-sm text-left">
                    <thead className="bg-slate-50 border-b text-slate-500 uppercase text-xs">
                        <tr><th className="p-4">Name</th>{isAdmin && <th className="p-4">Client</th>}<th className="p-4">Type</th><th className="p-4 text-right">Action</th></tr>
                    </thead>
                    <tbody>
                        {files.length === 0 ? <tr><td colSpan="5" className="p-6 text-center text-slate-400">No files found.</td></tr> : files.map(f => {
                            const isDrive = f.external_url && f.external_url.startsWith('drive:');
                            // Secure Proxy URL
                            const url = isDrive ? `${API_URL}?action=download_file&token=${encodeURIComponent(token)}&file_id=${f.id}` : f.external_url;
                            return (
                                <tr key={f.id} className="border-b hover:bg-slate-50">
                                    <td className="p-4 font-bold flex gap-3 items-center text-[#2c3259]">
                                        {isDrive ? <Icons.Cloud className="text-blue-500" size={18}/> : <Icons.Link className="text-orange-400" size={18}/>} {f.filename}
                                    </td>
                                    {isAdmin && <td className="p-4 text-slate-600">{f.client_name}</td>}
                                    <td className="p-4 text-xs font-bold text-slate-400 uppercase">{f.file_type ? f.file_type.split('/').pop() : 'FILE'}</td>
                                    <td className="p-4 text-right flex gap-2 justify-end">
                                        <a href={url} target="_blank" className="text-blue-600 font-bold text-xs border border-blue-200 bg-blue-50 px-3 py-1 rounded hover:bg-blue-100">{isDrive ? 'Download' : 'Open'}</a>
                                        {isAdmin && <button onClick={()=>handleDelete(f.id)} className="text-red-400 hover:text-red-600 p-1"><Icons.Trash size={16}/></button>}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {show && (
                <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50 backdrop-blur-sm">
                    <div className="bg-white p-8 rounded-xl w-full max-w-md relative shadow-2xl">
                        <button onClick={()=>setShow(false)} className="absolute top-4 right-4"><Icons.Close/></button>
                        <h3 className="font-bold text-xl mb-4 text-[#2c3259]">Upload to Secure Storage</h3>
                        <form onSubmit={handleUpload} className="space-y-4">
                            {isAdmin && (<div><label className="text-xs font-bold text-slate-500">Assign to Client</label><select name="client_id" className="w-full p-2 border rounded"><option value="">-- Myself / General --</option>{clients.map(c=><option key={c.id} value={c.id}>{c.full_name}</option>)}</select></div>)}
                            <input type="file" name="file" className="w-full text-sm file:bg-[#2c3259] file:text-white file:rounded-full file:px-4 file:py-2 file:border-0" required />
                            <p className="text-xs text-slate-400 text-center">Stored securely in Google Drive &gt; Client Folder</p>
                            <button className="w-full bg-[#2c3259] text-white p-3 rounded-lg font-bold">Upload Now</button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};

window.ServicesView = ({ token, role }) => {
    const Icons = window.Icons;
    const [services, setServices] = React.useState([]); 
    const [loading, setLoading] = React.useState(true); 
    const [modal, setModal] = React.useState(null); 
    const [editingItem, setEditingItem] = React.useState(null); 
    const isAdmin = role === 'admin';

    React.useEffect(() => { 
        setLoading(true); 
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_services', token }) })
        .then(res => { 
            if(res && res.status === 'success' && res.data && Array.isArray(res.data.services)) { 
                setServices(res.data.services); 
            } else { 
                setServices([]); 
            } 
        })
        .finally(()=>setLoading(false)); 
    }, [token, modal]);

    const handleCreateProduct = async (e) => { e.preventDefault(); const f = new FormData(e.target); const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'create_product', token, name: f.get('name'), description: f.get('description'), amount: f.get('amount'), interval: f.get('interval'), category: f.get('category') }) }); if(res.status === 'success') { setModal(null); } else alert(res.message); };
    const handleUpdateProduct = async (e) => { e.preventDefault(); const f = new FormData(e.target); const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'update_product', token, product_id: editingItem.id, name: f.get('name'), description: f.get('description'), category: f.get('category') }) }); if(res.status === 'success') { setModal(null); } else alert(res.message); };
    const handleDeleteProduct = async (id) => { if(!confirm("Delete this product?")) return; const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'delete_product', token, product_id: id }) }); if(res.status === 'success') { setModal(null); setServices(services.filter(s=>s.id!==id)); } else alert(res.message); };
    const handleToggleVisibility = async (id, currentHidden) => { const newHidden = !currentHidden; setServices(services.map(s => s.id === id ? { ...s, is_hidden: newHidden } : s)); await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'toggle_product_visibility', token, product_id: id, hidden: newHidden }) }); };
    const handleBuy = async (pid, interval) => { if(isAdmin) return alert("Admins cannot buy."); const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'create_checkout', token, price_id: pid, interval: interval }) }); if(res.status === 'success') window.location.href = res.url; else alert(res.message); };
    const handleSaveOrder = async (items) => { const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'save_service_order', token, items }) }); if(res.status === 'success') { setModal(null); } else alert("Save failed"); };

    const groupedServices = services.reduce((acc, service) => { const cat = service.category || 'General'; if (!acc[cat]) acc[cat] = []; acc[cat].push(service); return acc; }, {});
    const sortedCategories = Object.keys(groupedServices).sort((a, b) => ((groupedServices[a][0]?.cat_sort ?? 999) - (groupedServices[b][0]?.cat_sort ?? 999)));
    sortedCategories.forEach(cat => { groupedServices[cat].sort((a, b) => (a.prod_sort ?? 999) - (b.prod_sort ?? 999)); });

    if(loading) return <div className="p-8 text-center"><Icons.Loader/></div>;
    return (
        <div className="space-y-8 animate-fade-in"><div className="flex justify-between items-center"><h2 className="text-2xl font-bold text-[#2c3259]">Services</h2><div className="flex gap-2">{isAdmin && <button onClick={()=>setModal('sort')} className="bg-slate-100 text-slate-600 px-3 py-2 rounded hover:bg-slate-200" title="Arrange"><Icons.Menu size={18}/></button>}{isAdmin && <button onClick={()=>setModal('product')} className="bg-[#2c3259] text-white px-4 py-2 rounded flex items-center gap-1"><Icons.Plus size={16}/> New Product</button>}</div></div>{sortedCategories.length === 0 ? <div className="text-center p-10 text-slate-400 border-2 border-dashed rounded-xl">No services found.</div> : sortedCategories.map(cat => (<div key={cat}><h3 className="text-xl font-bold text-[#2c3259] mb-4 border-b border-slate-200 pb-2">{cat}</h3><div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">{groupedServices[cat].map(s => (<ServiceCard key={s.id} product={s} isAdmin={isAdmin} onBuy={handleBuy} onEdit={(p) => { setEditingItem(p); setModal('edit'); }} onDelete={handleDeleteProduct} onToggleVisibility={handleToggleVisibility}/>))}</div></div>))}
        {modal==='sort' && <ServiceSortModal services={groupedServices} onClose={()=>setModal(null)} onSave={handleSaveOrder} />}
        {modal==='product' && <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50"><div className="bg-white p-8 rounded-xl w-full max-w-md relative"><button onClick={()=>setModal(null)} className="absolute top-4 right-4"><Icons.Close/></button><h3 className="font-bold text-xl mb-4">New Product</h3><form onSubmit={handleCreateProduct} className="space-y-4"><input name="name" placeholder="Name" className="w-full p-2 border rounded" required/><input name="description" placeholder="Desc" className="w-full p-2 border rounded"/><input name="category" placeholder="Category" className="w-full p-2 border rounded" /><div className="flex gap-2"><input name="amount" type="number" placeholder="$" className="w-full p-2 border rounded" required/><select name="interval" className="p-2 border rounded"><option value="one-time">Once</option><option value="month">Month</option><option value="year">Year</option></select></div><button className="w-full bg-[#2c3259] text-white p-2 rounded font-bold">Save</button></form></div></div>}
        {modal==='edit' && editingItem && (<div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50"><div className="bg-white p-8 rounded-xl w-full max-w-md relative"><button onClick={()=>{setModal(null); setEditingItem(null);}} className="absolute top-4 right-4"><Icons.Close/></button><h3 className="font-bold text-xl mb-4">Edit Product</h3><form onSubmit={handleUpdateProduct} className="space-y-4"><input name="name" defaultValue={editingItem.name} className="w-full p-2 border rounded" required/><textarea name="description" defaultValue={editingItem.description} className="w-full p-2 border rounded h-24"/><input name="category" defaultValue={editingItem.category} className="w-full p-2 border rounded" placeholder="Category"/><button className="w-full bg-[#2c3259] text-white p-2 rounded font-bold">Update Details</button></form></div></div>)}
        </div>
    );
};

window.PartnerDashboard = ({ token, setView }) => {
    const Icons = window.Icons;
    const [stats, setStats] = React.useState({ client_count: 0, projects: [] });
    
    React.useEffect(() => {
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_partner_dashboard', token }) })
            .then(r => r.status==='success' && setStats(r));
    }, [token]);

    const handleNav = (id) => {
        localStorage.setItem('pending_nav', JSON.stringify({ view: 'projects', target_id: id }));
        window.dispatchEvent(new CustomEvent('switch_view', { detail: 'projects' }));
    };

    return (
        <div className="space-y-6 animate-fade-in">
            <div className="bg-[#2c3259] text-white p-8 rounded-2xl shadow-lg">
                <h2 className="text-3xl font-bold">Partner Command</h2>
                <p className="text-white/60 text-sm mt-1">You are managing {stats.client_count} client(s).</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div onClick={()=>setView('projects')} className="bg-white p-6 rounded-xl border shadow-sm cursor-pointer hover:border-[#2493a2] group">
                    <div className="flex items-center gap-3 mb-4">
                        <div className="p-3 bg-orange-100 text-orange-600 rounded-lg group-hover:bg-[#2493a2] group-hover:text-white transition-colors"><Icons.Folder /></div>
                        <h3 className="font-bold text-lg text-slate-800">Managed Projects</h3>
                    </div>
                    <p className="text-sm text-slate-500">{stats.projects.length} Active</p>
                </div>
                <div onClick={()=>setView('support')} className="bg-white p-6 rounded-xl border shadow-sm cursor-pointer hover:border-[#2493a2] group">
                    <div className="flex items-center gap-3 mb-4">
                        <div className="p-3 bg-blue-100 text-blue-600 rounded-lg group-hover:bg-[#2493a2] group-hover:text-white transition-colors"><Icons.MessageSquare /></div>
                        <h3 className="font-bold text-lg text-slate-800">Client Tickets</h3>
                    </div>
                    <p className="text-sm text-slate-500">View Thread History</p>
                </div>
            </div>

            <div className="bg-white rounded-xl border p-6 shadow-sm">
                <h3 className="font-bold text-lg text-[#2c3259] mb-4">Your Active Portfolio</h3>
                {stats.projects.length === 0 ? <p className="text-slate-400 text-xs">No active projects assigned.</p> : stats.projects.map(p=>(
                    <div key={p.id} onClick={()=>handleNav(p.id)} className="flex justify-between py-3 border-b last:border-0 cursor-pointer hover:bg-slate-50 transition-colors group">
                        <div>
                            <span className="font-bold text-slate-700 group-hover:text-[#2493a2] block">{p.title}</span>
                            <span className="text-xs text-slate-400">{p.client_name}</span>
                        </div>
                        <div className="flex items-center gap-2">
                             <div className="w-16 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                <div className={`h-full ${p.health_score > 50 ? 'bg-green-500' : 'bg-red-500'}`} style={{width: p.health_score+'%'}}></div>
                            </div>
                            <span className={`text-[10px] px-2 py-1 rounded font-bold uppercase ${p.status==='active'?'bg-green-100 text-green-700':'bg-slate-100 text-slate-500'}`}>{p.status}</span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};

window.AdminDashboard = ({ token, setView }) => {
    const [stats, setStats] = React.useState({}); 
    const [projects, setProjects] = React.useState([]); 
    const [invoices, setInvoices] = React.useState([]);
    
    React.useEffect(() => { 
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_admin_dashboard', token }) }).then(r => r.status==='success' && setStats(r.stats||{}));
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_projects', token }) }).then(r => r.status==='success' && setProjects(r.projects||[]));
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_billing_overview', token }) }).then(r => r.status==='success' && setInvoices(r.invoices||[]));
    }, [token]);

    const handleNav = (view, id) => {
        localStorage.setItem('pending_nav', JSON.stringify({ view, target_id: id }));
        window.dispatchEvent(new CustomEvent('switch_view', { detail: view }));
    };

    // Filters
    const activeProjects = projects.filter(p => p.status !== 'archived' && p.status !== 'complete').slice(0, 5);
    const openInvoices = invoices.filter(i => i.status !== 'paid' && i.status !== 'void').slice(0, 5);

    return (
        <div className="space-y-8 animate-fade-in">
            <window.FirstMate stats={stats} projects={projects} token={token} role="admin" />
            
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {['Available','Incoming','Reserved'].map((l,i)=>(<div key={l} className="bg-white p-6 rounded-xl shadow-sm border"><p className="text-xs font-bold text-slate-400 uppercase">{l}</p><h3 className="text-3xl font-bold text-[#2c3259]">{i===0?stats.stripe_available:i===1?stats.stripe_incoming:stats.stripe_reserved}</h3></div>))}
            </div>
            
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {/* Active Projects Widget */}
                <div className="bg-white rounded-xl border p-6 shadow-sm">
                    <div className="flex justify-between items-center mb-4">
                        <h3 className="font-bold text-lg text-[#2c3259]">Active Projects</h3>
                        <button onClick={()=>setView('projects')} className="text-xs font-bold text-blue-600 hover:underline">View All</button>
                    </div>
                    {activeProjects.length === 0 ? <p className="text-slate-400 text-xs">No active projects.</p> : activeProjects.map(p=>(
                        <div key={p.id} onClick={()=>handleNav('projects', p.id)} className="flex justify-between py-3 border-b last:border-0 cursor-pointer hover:bg-slate-50 transition-colors group">
                            <span className="font-medium text-slate-700 group-hover:text-[#2493a2]">{p.title}</span>
                            <span className={`text-[10px] px-2 py-1 rounded font-bold uppercase ${p.health_score>80?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}`}>{p.status}</span>
                        </div>
                    ))}
                </div>

                {/* Open Invoices Widget */}
                <div className="bg-white rounded-xl border p-6 shadow-sm">
                    <div className="flex justify-between items-center mb-4">
                        <h3 className="font-bold text-lg text-[#2c3259]">Open Invoices</h3>
                        <button onClick={()=>setView('billing')} className="text-xs font-bold text-blue-600 hover:underline">View All</button>
                    </div>
                    {openInvoices.length === 0 ? <p className="text-slate-400 text-xs">No open invoices.</p> : openInvoices.map(i=>(
                        <div key={i.id} onClick={()=>setView('billing')} className="flex justify-between py-3 border-b last:border-0 cursor-pointer hover:bg-slate-50 transition-colors group">
                            <span className="font-medium text-slate-700 group-hover:text-[#2493a2]">{i.client_name}</span>
                            <span className="font-bold font-mono text-slate-800">${i.amount}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

window.LoginScreen = ({ setSession }) => {
    const [isRegistering, setIsRegistering] = React.useState(false); const [email, setEmail] = React.useState(""); const [password, setPassword] = React.useState(""); const [confirmPass, setConfirmPass] = React.useState(""); const [name, setName] = React.useState(""); const [business, setBusiness] = React.useState(""); const [error, setError] = React.useState(""); const [loading, setLoading] = React.useState(false);
    React.useEffect(() => { const p = new URLSearchParams(window.location.search); if (p.get('action') === 'register') setIsRegistering(true); }, []);
    const handleSubmit = async (e) => { e.preventDefault(); setLoading(true); setError(""); try { if (isRegistering) { if (password !== confirmPass) throw new Error("Passwords do not match"); const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'register', email, password, name, business_name: business }) }); if (res.status === 'success') { const s = { token: res.token, user_id: res.user.id, role: res.user.role, name: res.user.name }; localStorage.setItem('wandweb_session', JSON.stringify(s)); setSession(s); } else setError(res.message); } else { const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'login', email, password }) }); if (res.status === 'success') { const s = { token: res.token, user_id: res.user.id, role: res.user.role, name: res.user.name }; localStorage.setItem('wandweb_session', JSON.stringify(s)); setSession(s); } else setError(res.message); } } catch (err) { setError(err.message); } setLoading(false); };
    return (<div className="min-h-screen flex items-center justify-center p-4 relative overflow-hidden"><window.PortalBackground /><div className="w-full max-w-md bg-[#2c3259] p-10 rounded-2xl shadow-2xl border border-slate-600/50 relative z-10"><div className="text-center mb-8"><img src={LOGO_URL} className="h-20 mx-auto mb-4 object-contain"/><h1 className="text-2xl font-bold text-white">{isRegistering ? 'Create Account' : 'Client Portal'}</h1></div><form onSubmit={handleSubmit} className="space-y-5">{isRegistering && (<><div><label className="block text-xs font-bold text-[#2493a2] uppercase mb-2">Full Name</label><input value={name} onChange={e => setName(e.target.value)} className="w-full p-3.5 bg-slate-800/50 border border-slate-600 rounded-xl text-white" required /></div><div><label className="block text-xs font-bold text-[#2493a2] uppercase mb-2">Business Name</label><input value={business} onChange={e => setBusiness(e.target.value)} className="w-full p-3.5 bg-slate-800/50 border border-slate-600 rounded-xl text-white" required /></div></>)}<div><label className="block text-xs font-bold text-[#2493a2] uppercase mb-2">Email</label><input type="email" value={email} onChange={e => setEmail(e.target.value)} className="w-full p-3.5 bg-slate-800/50 border border-slate-600 rounded-xl text-white" required /></div><div><label className="block text-xs font-bold text-[#2493a2] uppercase mb-2">Password</label><input type="password" value={password} onChange={e => setPassword(e.target.value)} className="w-full p-3.5 bg-slate-800/50 border border-slate-600 rounded-xl text-white" required /></div>{isRegistering && <div><label className="block text-xs font-bold text-[#2493a2] uppercase mb-2">Confirm</label><input type="password" value={confirmPass} onChange={e => setConfirmPass(e.target.value)} className="w-full p-3.5 bg-slate-800/50 border border-slate-600 rounded-xl text-white" required /></div>}{error && <div className="text-red-200 text-sm bg-red-900/50 p-2 rounded">{error}</div>}<button disabled={loading} className="w-full bg-[#dba000] text-white p-4 rounded-xl font-bold">{loading ? '...' : (isRegistering ? 'Create Account' : 'Sign In')}</button></form><button onClick={() => setIsRegistering(!isRegistering)} className="mt-6 text-slate-400 text-sm underline w-full text-center">{isRegistering ? "Sign In" : "Register"}</button></div></div>);
};
window.SetPasswordScreen = ({ token }) => { const [p, setP] = React.useState(""); const [c, setC] = React.useState(""); const handleSubmit = async (e) => { e.preventDefault(); if (p !== c) return alert("Mismatch"); const r = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'set_password', invite_token: token, password: p }) }); if (r.status === 'success') window.location.href = "/portal/"; else alert(r.message); }; return (<div className="min-h-screen flex items-center justify-center bg-slate-50"><form onSubmit={handleSubmit} className="bg-white p-8 rounded-xl shadow-lg w-full max-w-sm"><h2 className="text-xl font-bold mb-4">Set Password</h2><input type="password" placeholder="New Password" onChange={e=>setP(e.target.value)} className="w-full p-3 border rounded mb-2" required /><input type="password" placeholder="Confirm" onChange={e=>setC(e.target.value)} className="w-full p-3 border rounded mb-4" required /><button className="w-full bg-[#dba000] text-white p-3 rounded font-bold">Set</button></form></div>); };
window.BillingView = ({ token, role }) => { const Icons = window.Icons; const [data, setData] = React.useState(null); const [clients, setClients] = React.useState([]); const [loading, setLoading] = React.useState(true); const [activeTab, setActiveTab] = React.useState('feed'); const [showModal, setShowModal] = React.useState(false); const [editInvoiceId, setEditInvoiceId] = React.useState(null); const [billingMode, setBillingMode] = React.useState('invoice'); const [selectedItems, setSelectedItems] = React.useState([]); const [invSettingsOpen, setInvSettingsOpen] = React.useState(false); const [collectionMethod, setCollectionMethod] = React.useState('send_invoice'); const [daysUntilDue, setDaysUntilDue] = React.useState(7); const [invMemo, setInvMemo] = React.useState(''); const [invFooter, setInvFooter] = React.useState(''); const [coupon, setCoupon] = React.useState(''); const [clientSearch, setClientSearch] = React.useState(''); const [feedFilter, setFeedFilter] = React.useState('all'); const [feedSort, setFeedSort] = React.useState('newest'); const [invFilter, setInvFilter] = React.useState('all'); const [invSort, setInvSort] = React.useState('newest'); const [quoteFilter, setQuoteFilter] = React.useState('all'); const [quoteSort, setQuoteSort] = React.useState('newest'); const [subFilter, setSubFilter] = React.useState('active'); const [subSort, setSubSort] = React.useState('newest'); const [payoutSort, setPayoutSort] = React.useState('newest'); const isAdmin = role === 'admin'; React.useEffect(() => { loadData(); if(isAdmin) window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) }).then(res => { if(res && res.status === 'success') setClients(res.clients); }); }, [token]); const loadData = () => { setLoading(true); window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_billing_overview', token }) }).then(res => { if(res && res.status === 'success') setData(res.data || res.stats || res.invoices); }).finally(()=>setLoading(false)); }; const handleManageSub = async () => { const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_stripe_portal', token }) }); if(res.status === 'success') window.location.href = res.url; else alert(res.message); }; const handleSubmitBilling = async (e) => { e.preventDefault(); const f = new FormData(e.target); const clientId = f.get('client_id'); const sendNow = f.get('send_now') === 'on'; const payload = { token, client_id: clientId, items: selectedItems, collection_method: collectionMethod, days_until_due: daysUntilDue, memo: invMemo, footer: invFooter, coupon: coupon }; if(billingMode === 'invoice') { payload.action = editInvoiceId ? 'update_invoice_draft' : 'create_invoice'; payload.send_now = sendNow; if(editInvoiceId) payload.invoice_id = editInvoiceId; const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify(payload) }); if(res.status==='success') { setShowModal(false); loadData(); } else alert(res.message); } else if(billingMode === 'quote') { payload.action = 'create_quote'; payload.send_now = sendNow; const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify(payload) }); if(res.status==='success') { setShowModal(false); loadData(); } else alert(res.message); } else { payload.action = 'create_subscription_manually'; const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify(payload) }); if(res.status==='success') { setShowModal(false); loadData(); } else alert(res.message); } }; const handleInvAction = async (id, act) => { const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'invoice_action', token, invoice_id: id, sub_action: act }) }); if(res.status === 'success') { loadData(); } else alert(res.message); }; const handleQuoteAction = async (id, act) => { const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'quote_action', token, quote_id: id, sub_action: act }) }); if(res.status === 'success') { loadData(); } else alert(res.message); }; const handleSubAction = async (id, act) => { const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'subscription_action', token, subscription_id: id, sub_action: act }) }); if(res.status === 'success') { loadData(); } else alert(res.message); }; const openEdit = async (inv) => { const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_invoice_details', token, invoice_id: inv.id }) }); if(res.status === 'success') { const fullInv = res.invoice; setBillingMode('invoice'); setEditInvoiceId(fullInv.id); const existingItems = (fullInv.lines || []).map(l => ({ price_id: l.price_id, name: l.product_name, amount: l.amount })); setSelectedItems(existingItems); setCollectionMethod(fullInv.collection_method || 'send_invoice'); setDaysUntilDue(fullInv.days_until_due || 7); setInvMemo(fullInv.description || ''); setInvFooter(fullInv.footer || ''); setShowModal(true); } else { alert("Could not load details."); } }; const openNew = () => { setBillingMode('invoice'); setEditInvoiceId(null); setSelectedItems([]); setCollectionMethod('send_invoice'); setDaysUntilDue(7); setInvMemo(''); setInvFooter(''); setCoupon(''); setInvSettingsOpen(false); setShowModal(true); }; const filterBySearch = (items) => { if (!clientSearch.trim()) return items; const term = clientSearch.toLowerCase(); return items.filter(item => { const name = item.client_name || item.title || ''; const email = item.email || item.meta?.email || ''; return name.toLowerCase().includes(term) || email.toLowerCase().includes(term); }); }; if(loading) return <div className="p-8 text-center"><Icons.Loader/></div>; let feedItems = (data && data.feed ? data.feed : []).filter(i => feedFilter === 'all' || i.type === feedFilter); feedItems = filterBySearch(feedItems).sort((a, b) => feedSort === 'newest' ? b.date_ts - a.date_ts : a.date_ts - b.date_ts); let invoiceItems = (data && data.invoices ? data.invoices : []).filter(i => invFilter === 'all' || i.status === invFilter); invoiceItems = filterBySearch(invoiceItems).sort((a, b) => { if(invSort === 'newest') return b.date_ts - a.date_ts; if(invSort === 'oldest') return a.date_ts - b.date_ts; return 0; }); let quoteItems = (data && data.quotes ? data.quotes : []).filter(q => quoteFilter === 'all' || q.status === quoteFilter); quoteItems = filterBySearch(quoteItems).sort((a, b) => { if(quoteSort === 'newest') return b.date_ts - a.date_ts; if(quoteSort === 'oldest') return a.date_ts - b.date_ts; return 0; }); let subItems = (data && data.subscriptions ? data.subscriptions : []).filter(s => subFilter === 'all' || s.status === subFilter); subItems = filterBySearch(subItems).sort((a, b) => { if(subSort === 'newest') return b.date_ts - a.date_ts; if(subSort === 'oldest') return a.date_ts - b.date_ts; return 0; }); let payoutItems = (data && data.feed ? data.feed : []).filter(i => i.type === 'payout').sort((a, b) => b.date_ts - a.date_ts); if(isAdmin) { return ( <div className="space-y-6 animate-fade-in"><div className="flex gap-4 border-b border-slate-200 pb-1 overflow-x-auto">{['feed', 'invoices', 'quotes', 'subscriptions', 'payouts'].map(tab => (<button key={tab} onClick={() => setActiveTab(tab)} className={`px-4 py-2 text-sm font-bold capitalize whitespace-nowrap ${activeTab === tab ? 'text-[#2c3259] border-b-2 border-[#2c3259]' : 'text-slate-400 hover:text-slate-600'}`}>{tab}</button>))} <button onClick={openNew} className="ml-auto bg-[#2c3259] text-white px-4 py-2 rounded text-sm shadow hover:bg-slate-700 whitespace-nowrap">Issue Bill/Quote</button></div> {activeTab === 'feed' && (<div className="space-y-6"><div className="grid grid-cols-2 gap-6"><div className="bg-white p-6 rounded border"><p className="text-xs font-bold text-slate-400">Available</p><h3 className="text-2xl font-bold">{data && data.available}</h3></div><div className="bg-white p-6 rounded border"><p className="text-xs font-bold text-slate-400">Pending</p><h3 className="text-2xl font-bold">${data && data.pending}</h3></div></div><div><window.FilterSortToolbar filterOptions={[{value:'all', label:'All Types'}, {value:'payment', label:'Payments'}, {value:'invoice', label:'Invoices'}, {value:'quote', label:'Quotes'}, {value:'subscription', label:'Subscriptions'}, {value:'payout', label:'Payouts'}]} filterValue={feedFilter} onFilterChange={setFeedFilter} sortOrder={feedSort} onSortToggle={()=>setFeedSort(feedSort==='newest'?'oldest':'newest')} searchValue={clientSearch} onSearchChange={setClientSearch} /><div className="bg-white rounded border overflow-hidden max-h-[500px] overflow-y-auto">{feedItems.length === 0 ? <div className="p-8 text-center text-slate-400 text-sm">No activity found.</div> : <table className="w-full text-left text-sm"><thead className="bg-slate-50 border-b sticky top-0"><tr><th className="p-3">Type</th><th className="p-3">Description</th><th className="p-3">Amount</th><th className="p-3">Status</th><th className="p-3">Date</th></tr></thead><tbody>{feedItems.map(item => (<tr key={item.id + item.type} className="border-b hover:bg-slate-50 transition-colors"><td className="p-3">{item.type}</td><td className="p-3 font-medium text-slate-700">{item.title}</td><td className="p-3 font-bold">${item.amount}</td><td className="p-3"><span className={`px-2 py-1 rounded text-[10px] uppercase font-bold border ${item.status==='paid' || item.status==='accepted' || item.status==='active' ? 'bg-green-50 text-green-600 border-green-200' : 'bg-slate-50 text-slate-500 border-slate-200'}`}>{item.status}</span></td><td className="p-3 text-xs text-slate-400">{item.date_display}</td></tr>))}</tbody></table>}</div></div></div>)} {activeTab === 'invoices' && (<div><window.FilterSortToolbar filterOptions={[{value:'all', label:'All Status'}, {value:'draft', label:'Draft'}, {value:'open', label:'Open'}, {value:'paid', label:'Paid'}, {value:'void', label:'Void'}]} filterValue={invFilter} onFilterChange={setInvFilter} sortOrder={invSort} onSortToggle={()=>setInvSort(invSort==='newest'?'oldest':'newest')} searchValue={clientSearch} onSearchChange={setClientSearch} /><div className="bg-white rounded border overflow-hidden">{invoiceItems.length === 0 ? <div className="p-8 text-center text-slate-400">No invoices found.</div> : <table className="w-full text-left text-sm"><thead className="bg-slate-50 border-b"><tr><th className="p-3">Number</th><th className="p-3">Client</th><th className="p-3">Date</th><th className="p-3">Amount</th><th className="p-3">Status</th><th className="p-3">Actions</th></tr></thead><tbody>{invoiceItems.map(i=><tr key={i.id} className="border-b"><td className="p-3 font-mono text-xs">{i.number}</td><td className="p-3 font-medium text-[#2c3259]">{i.client_name}</td><td className="p-3 text-slate-500">{i.date}</td><td className="p-3 font-bold">${i.amount}</td><td className="p-3"><span className={`px-2 py-1 rounded text-xs uppercase font-bold ${i.status==='paid'?'bg-green-100 text-green-700':'bg-red-100'}`}>{i.status}</span></td><td className="p-3 flex gap-2 items-center">{i.status==='draft' && <button onClick={()=>openEdit(i)} className="text-blue-600 hover:underline text-xs font-bold">Edit</button>}{i.status==='open' && <button onClick={()=>handleInvAction(i.id,'void')} className="text-red-500 hover:underline text-xs">Void</button>}{i.status==='draft' && <button onClick={()=>handleInvAction(i.id,'delete')} className="text-red-500 hover:underline text-xs">Delete</button>}<a href={i.pdf} target="_blank" className="text-blue-600 hover:underline text-xs">PDF</a></td></tr>)}</tbody></table>}</div></div>)} {activeTab === 'quotes' && (<div><window.FilterSortToolbar filterOptions={[{value:'all', label:'All Status'}, {value:'draft', label:'Draft'}, {value:'open', label:'Open'}, {value:'accepted', label:'Accepted'}, {value:'canceled', label:'Canceled'}]} filterValue={quoteFilter} onFilterChange={setQuoteFilter} sortOrder={quoteSort} onSortToggle={()=>setQuoteSort(quoteSort==='newest'?'oldest':'newest')} searchValue={clientSearch} onSearchChange={setClientSearch} /><div className="bg-white rounded border overflow-hidden">{quoteItems.length === 0 ? <div className="p-8 text-center text-slate-400">No quotes found.</div> : <table className="w-full text-left text-sm"><thead className="bg-slate-50 border-b"><tr><th className="p-3">Number</th><th className="p-3">Client</th><th className="p-3">Date</th><th className="p-3">Amount</th><th className="p-3">Status</th><th className="p-3">Actions</th></tr></thead><tbody>{quoteItems.map(q=><tr key={q.id} className="border-b"><td className="p-3 font-mono text-xs">{q.number || 'DRAFT'}</td><td className="p-3 font-medium text-[#2c3259]">{q.client_name}</td><td className="p-3 text-slate-500">{q.date}</td><td className="p-3 font-bold">${q.amount}</td><td className="p-3"><span className={`px-2 py-1 rounded text-xs uppercase font-bold ${q.status==='accepted'?'bg-green-100 text-green-700':(q.status==='open'?'bg-blue-100 text-blue-700':'bg-slate-100')}`}>{q.status}</span></td> <td className="p-3 flex gap-2 items-center"> {q.status==='draft' && <button onClick={()=>handleQuoteAction(q.id,'finalize')} className="text-blue-600 hover:underline text-xs font-bold">Send</button>} {q.status==='open' && <button onClick={()=>handleQuoteAction(q.id,'accept')} className="text-green-600 hover:underline text-xs">Accept</button>} {(q.status==='draft'||q.status==='open') && <button onClick={()=>handleQuoteAction(q.id,'cancel')} className="text-red-500 hover:underline text-xs">Cancel</button>} </td></tr>)}</tbody></table>}</div></div>)} {activeTab === 'subscriptions' && (<div><window.FilterSortToolbar filterOptions={[{value:'all', label:'All Status'}, {value:'active', label:'Active'}, {value:'past_due', label:'Past Due'}, {value:'canceled', label:'Canceled'}]} filterValue={subFilter} onFilterChange={setSubFilter} sortOrder={subSort} onSortToggle={()=>setSubSort(subSort==='newest'?'oldest':'newest')} searchValue={clientSearch} onSearchChange={setClientSearch} /><div className="bg-white rounded border overflow-hidden"><table className="w-full text-left text-sm"><thead className="bg-slate-50 border-b"><tr><th className="p-3">Client</th><th className="p-3">Plan</th><th className="p-3">Amount</th><th className="p-3">Status</th><th className="p-3">Next Bill</th><th className="p-3">Action</th></tr></thead><tbody>{subItems.map(s=><tr key={s.id} className="border-b"><td className="p-3 text-xs font-bold text-[#2c3259]">{s.client_name}</td><td className="p-3 font-medium">{s.plan}</td><td className="p-3">${s.amount}/{s.interval}</td><td className="p-3"><span className={`px-2 py-1 rounded text-xs uppercase font-bold ${s.status==='active'?'bg-green-100 text-green-800':'bg-red-100 text-red-800'}`}>{s.status}</span></td><td className="p-3 text-slate-500 text-xs">{s.next_bill}</td><td className="p-3"><button onClick={()=>handleSubAction(s.id,'cancel')} className="text-red-500 hover:underline text-xs">Cancel</button></td></tr>)}</tbody></table></div></div>)} {activeTab === 'payouts' && (<div><div className="bg-white rounded border overflow-hidden">{payoutItems.length === 0 ? <div className="p-8 text-center text-slate-400">No payouts found.</div> : <table className="w-full text-left text-sm"><thead className="bg-slate-50 border-b"><tr><th className="p-3">Amount</th><th className="p-3">Status</th><th className="p-3">Arrival</th></tr></thead><tbody>{payoutItems.map(p=><tr key={p.id} className="border-b"><td className="p-3 font-bold">${p.amount}</td><td className="p-3 capitalize"><span className="bg-slate-100 px-2 py-1 rounded text-xs font-bold">{p.status}</span></td><td className="p-3 text-slate-500">{p.meta?.arrival || p.date_display}</td></tr>)}</tbody></table>}</div></div>)} {showModal && <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50"><div className="bg-white p-6 rounded-xl w-full max-w-2xl relative max-h-[90vh] overflow-y-auto"><button onClick={()=>setShowModal(false)} className="absolute top-4 right-4"><Icons.Close/></button><h3 className="font-bold text-xl mb-4">{editInvoiceId ? 'Edit Draft Invoice' : 'Create Billing'}</h3>{!editInvoiceId && <div className="flex gap-2 mb-4 p-1 bg-slate-100 rounded-lg inline-flex"><button type="button" onClick={()=>setBillingMode('invoice')} className={`px-4 py-1.5 rounded-md text-sm font-bold transition-all ${billingMode==='invoice'?'bg-white shadow text-[#2c3259]':'text-slate-500'}`}>Invoice</button><button type="button" onClick={()=>setBillingMode('quote')} className={`px-4 py-1.5 rounded-md text-sm font-bold transition-all ${billingMode==='quote'?'bg-white shadow text-[#2c3259]':'text-slate-500'}`}>Quote</button><button type="button" onClick={()=>setBillingMode('subscription')} className={`px-4 py-1.5 rounded-md text-sm font-bold transition-all ${billingMode==='subscription'?'bg-white shadow text-[#2c3259]':'text-slate-500'}`}>Subscription</button></div>}<form onSubmit={handleSubmitBilling} className="space-y-4">{!editInvoiceId && <select name="client_id" className="w-full p-2 border rounded" required><option value="">Select Client...</option>{clients.map(c=><option key={c.id} value={c.id}>{c.full_name}</option>)}</select>}<div><label className="block text-xs font-bold text-slate-500 mb-2 uppercase">Select Products ({billingMode})</label><window.ProductSelector token={token} selectedItems={selectedItems} onChange={setSelectedItems} filterMode={billingMode === 'subscription' ? 'recurring' : 'all'} /></div><div className="bg-slate-50 p-4 rounded-lg border"><div className="text-xs font-bold text-slate-500 uppercase mb-2">Summary</div>{selectedItems.length === 0 ? <p className="text-sm text-slate-400 italic">No items selected.</p> : selectedItems.map((item, i) => (<div key={i} className="flex justify-between text-sm py-1"><span>{item.name}</span><span className="font-mono">${item.amount}</span></div>))}<div className="border-t mt-2 pt-2 flex justify-between font-bold text-lg text-[#2c3259]"><span>Total</span><span>${selectedItems.reduce((a,b)=>a+parseFloat(b.amount),0).toFixed(2)}</span></div></div><div className="border rounded-lg overflow-hidden"><button type="button" onClick={() => setInvSettingsOpen(!invSettingsOpen)} className="w-full p-3 bg-slate-100 text-left flex justify-between items-center text-sm font-bold text-slate-700"><span>Advanced Settings (Payment, Memo, Footer)</span>{invSettingsOpen ? <Icons.ChevronUp size={16}/> : <Icons.ChevronDown size={16}/>}</button>{invSettingsOpen && <div className="p-4 space-y-4 bg-white"><div className="grid grid-cols-2 gap-4"><div><label className="block text-xs font-bold text-slate-500 mb-1">Collection Method</label><select value={collectionMethod} onChange={e=>setCollectionMethod(e.target.value)} className="w-full p-2 border rounded text-sm"><option value="send_invoice">Email Invoice/Quote to Client</option>{billingMode !== 'quote' && <option value="charge_automatically">Auto-Charge Card on File</option>}</select></div>{collectionMethod === 'send_invoice' && billingMode !== 'quote' && (<div><label className="block text-xs font-bold text-slate-500 mb-1">Days until Due</label><input type="number" value={daysUntilDue} onChange={e=>setDaysUntilDue(e.target.value)} className="w-full p-2 border rounded text-sm" /></div>)}</div><div><label className="block text-xs font-bold text-slate-500 mb-1">Memo (Visible to Client)</label><textarea value={invMemo} onChange={e=>setInvMemo(e.target.value)} className="w-full p-2 border rounded text-sm h-16" placeholder="Thanks for your business!"></textarea></div><div><label className="block text-xs font-bold text-slate-500 mb-1">Footer (Visible to Client)</label><input value={invFooter} onChange={e=>setInvFooter(e.target.value)} className="w-full p-2 border rounded text-sm" placeholder="Wandering Webmaster | ABN..." /></ div><div><label className="block text-xs font-bold text-slate-500 mb-1">Discount Coupon (Optional)</label><input value={coupon} onChange={e=>setCoupon(e.target.value)} className="w-full p-2 border rounded text-sm" placeholder="e.g. SAVE20" /></div></div>}</div>{(billingMode === 'invoice' || billingMode === 'quote') && <div className="flex items-center gap-2"><input type="checkbox" name="send_now" id="send_now" className="w-4 h-4"/><label htmlFor="send_now" className="text-sm font-bold text-slate-700">Finalize & Send Immediately</label></div>}<button className="w-full bg-[#2c3259] text-white p-3 rounded-lg font-bold shadow-lg hover:bg-slate-700">{billingMode === 'invoice' ? (editInvoiceId ? 'Save Changes' : 'Create Draft') : (billingMode === 'quote' ? 'Create Quote' : 'Start Subscription')}</button></form></div></div>}</div>); }
    return (<div className="space-y-8 animate-fade-in"><div><div className="flex justify-between items-center mb-4"><h3 className="text-xl font-bold text-[#2c3259]">Active Subscriptions</h3><button onClick={handleManageSub} className="bg-[#2c3259] text-white px-4 py-2 rounded text-sm font-bold">Manage / Cancel</button></div><div className="bg-white rounded-xl border shadow-sm overflow-hidden"><table className="w-full text-left text-sm"><thead className="bg-slate-50 border-b"><tr><th className="p-4">Date</th><th className="p-4">Amount</th><th className="p-4">Status</th><th className="p-4 text-right">PDF</th></tr></thead><tbody>{(data?.invoices || []).map(i => (<tr key={i.id} className="border-b hover:bg-slate-50"><td className="p-4">{i.date}</td><td className="p-4 font-bold">${i.amount}</td><td className="p-4"><span className={`px-2 py-1 rounded text-xs uppercase font-bold ${i.status==='paid'?'bg-green-100 text-green-700':'bg-red-100'}`}>{i.status}</span></td><td className="p-4 text-right"><a href={i.pdf} target="_blank" className="text-blue-600 hover:underline">Download</a></td></tr>))}</tbody></table></div></div></div>); 
};

window.ProjectsView = ({ token, role, currentUserId }) => {
    const Icons = window.Icons;
    const [projects, setProjects] = React.useState([]); 
    const [clients, setClients] = React.useState([]); // Store clients for selector
    const [partners, setPartners] = React.useState([]); // Store partners for manager assignment
    const [active, setActive] = React.useState(null);
    const [showCreateModal, setShowCreateModal] = React.useState(false);
    const [showAssignManagerModal, setShowAssignManagerModal] = React.useState(false);
    const [selectedProjectForManager, setSelectedProjectForManager] = React.useState(null);
    const [selectedManager, setSelectedManager] = React.useState('');
    const [showArchived, setShowArchived] = React.useState(false);
    const [initialProjectData, setInitialProjectData] = React.useState(null);
    
    // Fetch Projects, Clients, AND Partners
    const loadData = () => {
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_projects', token }) }).then(r => setProjects(r.projects||[]));
        if (role === 'admin') {
            window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) }).then(r => setClients(r.clients||[]));
            // Fetch partners for manager assignment
            window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_partners', token }) }).then(r => setPartners(r.partners||[])).catch(() => setPartners([]));
        }
    };
    
    React.useEffect(() => { loadData(); }, [token]);
    
    // Listen for global open_project_modal from TicketThread
    React.useEffect(() => {
        const handler = (e) => {
            const d = e.detail || {};
            setInitialProjectData(d);
            setShowCreateModal(true);
        };
        window.addEventListener('open_project_modal', handler);
        return () => window.removeEventListener('open_project_modal', handler);
    }, []);
    
    // Deep Link Logic
    React.useEffect(() => {
        if (projects.length === 0) return; 
        const pending = localStorage.getItem('pending_nav');
        if(pending) {
            const nav = JSON.parse(pending);
            if(nav.view === 'projects' && nav.target_id) {
                const target = projects.find(p => p.id == nav.target_id);
                if(target) { setActive(target); localStorage.removeItem('pending_nav'); }
            }
        }
    }, [projects]);

    const handleCreateProject = async (payload) => {
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ token, ...payload }) });
        if(res.status === 'success') {
            setShowCreateModal(false);
            loadData();
        } else {
            alert('Error: ' + res.message);
        }
    };
    
    const handleDelete = async (id) => {
        if(!confirm('Delete this project?')) return;
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'delete_project', token, project_id: id }) });
        if(res.status === 'success') loadData();
    };
    
    const handleUpdateStatus = async (id, newStatus) => {
        const project = projects.find(p => p.id === id);
        const health_score = newStatus === 'archived' ? 0 : project?.health_score || 0;
        await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'update_project_status', token, project_id: id, status: newStatus, health_score }) });
        loadData();
    };

    const handleAssignManagerClick = (project) => {
        setSelectedProjectForManager(project);
        setSelectedManager(project.manager_id || '');
        setShowAssignManagerModal(true);
    };

    const handleAssignManager = async () => {
        if (!selectedProjectForManager) return;
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'assign_project_manager', token, project_id: selectedProjectForManager.id, manager_id: selectedManager }) });
        if(res.status === 'success') {
            setShowAssignManagerModal(false);
            loadData();
        } else {
            alert('Error: ' + res.message);
        }
    };

    const filteredProjects = projects.filter(p => showArchived ? p.status === 'archived' : p.status !== 'archived');
    
    if(active) return <TaskManager project={active} token={token} role={role} onClose={()=>setActive(null)} />;
    
    return (
        <div className="space-y-6 animate-fade-in">
            <div className="flex justify-between items-center">
                <h2 className="text-2xl font-bold text-[#2c3259]">Projects</h2>
                <div className="flex gap-3">
                    <button onClick={() => setShowArchived(!showArchived)} className={`px-4 py-2 rounded-lg text-sm font-bold transition-colors border flex items-center gap-2 ${showArchived ? 'bg-[#dba000] text-white border-[#dba000]' : 'bg-white text-slate-600 border-slate-300'}`}>
                        <Icons.Archive size={16}/> {showArchived ? 'Active' : 'Archived'}
                    </button>
                    {role === 'admin' && (
                        <button onClick={() => setShowCreateModal(true)} className="bg-[#2493a2] text-white px-4 py-2 rounded-lg font-bold flex items-center gap-2 shadow-md hover:bg-[#1e7e8b]">
                            <Icons.Plus size={16}/> New Project
                        </button>
                    )}
                </div>
            </div>

            {filteredProjects.length === 0 ? (
                <div className="p-20 text-center border-2 border-dashed rounded-xl text-slate-400">
                    <Icons.Folder size={48} className="mx-auto mb-4"/>
                    <p>{showArchived ? "No archived projects." : (role === 'admin' ? "No active projects. Click 'New Project' to begin." : "No active projects. Contact support to start a new engagement.")}</p>
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {filteredProjects.map(p=>
                        <ProjectCard key={p.id} project={p} role={role} setActiveProject={setActive} onDelete={handleDelete} onUpdateStatus={handleUpdateStatus} onAssignManager={handleAssignManagerClick} partners={partners} />
                    )}
                </div>
            )}

            {showCreateModal && (
                <CreateProjectModal 
                    clients={clients} 
                    onClose={()=>setShowCreateModal(false)} 
                    onSubmit={handleCreateProject}
                    initialData={initialProjectData}
                />
            )}

            {showAssignManagerModal && selectedProjectForManager && (
                <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-xl w-full max-w-md shadow-2xl">
                        <div className="p-6 border-b flex justify-between items-center">
                            <h3 className="font-bold text-lg">Assign Project Manager</h3>
                            <button onClick={() => setShowAssignManagerModal(false)}><Icons.Close/></button>
                        </div>
                        <div className="p-6 space-y-4">
                            <div>
                                <p className="font-bold text-slate-800 mb-2">Project: {selectedProjectForManager.title}</p>
                                <label className="block text-sm font-bold text-slate-600 mb-2">Select Manager</label>
                                <select value={selectedManager} onChange={(e) => setSelectedManager(e.target.value)} className="w-full p-3 border rounded-lg">
                                    <option value="">No Manager</option>
                                    {partners.map(p => <option key={p.id} value={p.id}>{p.full_name || p.email}</option>)}
                                </select>
                            </div>
                        </div>
                        <div className="p-6 border-t flex gap-3 justify-end">
                            <button onClick={() => setShowAssignManagerModal(false)} className="px-4 py-2 text-slate-600 font-bold hover:bg-slate-50 rounded">Cancel</button>
                            <button onClick={handleAssignManager} className="px-4 py-2 bg-[#2493a2] text-white font-bold rounded hover:bg-[#1e7e8b]">Assign Manager</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

window.OnboardingView = ({ token }) => { const [step, setStep] = React.useState(1); const [submitting, setSubmitting] = React.useState(false); const handleSubmit = async (e) => { e.preventDefault(); if (step < 3) { setStep(step + 1); return; } setSubmitting(true); const formData = new FormData(e.target); const data = Object.fromEntries(formData.entries()); data.action = 'submit_onboarding'; data.onboarding_token = token; const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify(data) }); if (res.status === 'success') { alert(res.message); window.location.href = '/portal/'; } else { alert("Error: " + res.message); setSubmitting(false); } }; return (<div className="min-h-screen bg-slate-50 flex items-center justify-center p-4"><div className="bg-white w-full max-w-2xl rounded-xl shadow-2xl overflow-hidden border border-slate-100"><div className="bg-[#2c3259] p-6 text-center"><img src={LOGO_URL} alt="WandWeb" className="h-16 mx-auto" /><p className="text-slate-300 mt-2">Project Onboarding</p></div><form onSubmit={handleSubmit} className="p-8">{step === 1 && (<div className="space-y-4 animate-fade-in"><h2 className="text-xl font-bold text-slate-800 border-b pb-2 mb-4">G'Day! Your Details</h2><div><label className="block text-sm font-bold text-slate-600">Prefix</label><select name="prefix" className="w-full p-3 border rounded bg-slate-50"><option>Mr</option><option>Mrs</option><option>Ms</option></select></div><div className="grid grid-cols-2 gap-4"><div><label className="block text-sm font-bold text-slate-600">First Name *</label><input name="first_name" className="w-full p-3 border rounded" required/></div><div><label className="block text-sm font-bold text-slate-600">Last Name *</label><input name="last_name" className="w-full p-3 border rounded" required/></div></div><div><label className="block text-sm font-bold text-slate-600">Email *</label><input name="email" type="email" className="w-full p-3 border rounded" required/></div><div><label className="block text-sm font-bold text-slate-600">Phone Number *</label><input name="phone" className="w-full p-3 border rounded" required/></div></div>)}{step === 2 && (<div className="space-y-4 animate-fade-in"><h2 className="text-xl font-bold text-slate-800 border-b pb-2 mb-4">Business Details</h2><div><label className="block text-sm font-bold text-slate-600">Business Name</label><input name="business_name" className="w-full p-3 border rounded"/></div><div><label className="block text-sm font-bold text-slate-600">Address</label><textarea name="address" className="w-full p-3 border rounded h-20"></textarea></div><div><label className="block text-sm font-bold text-slate-600">Position</label><input name="position" className="w-full p-3 border rounded" placeholder="Your position within the Business"/></div><div><label className="block text-sm font-bold text-slate-600">Website</label><input name="website" className="w-full p-3 border rounded" placeholder="Current URL (if any)"/></div></div>)}{step === 3 && (<div className="space-y-4 animate-fade-in"><h2 className="text-xl font-bold text-slate-800 border-b pb-2 mb-4">How can we best help you?</h2><div><label className="block text-sm font-bold text-slate-600">Project Goals</label><textarea name="goals" className="w-full p-3 border rounded h-24"></textarea></div><div><label className="block text-sm font-bold text-slate-600">Scope of Work</label><textarea name="scope" className="w-full p-3 border rounded h-24"></textarea></div><div className="grid grid-cols-2 gap-4"><div><label className="block text-sm font-bold text-slate-600">Timeline</label><input name="timeline" className="w-full p-3 border rounded"/></div><div><label className="block text-sm font-bold text-slate-600">Budget</label><input name="budget" className="w-full p-3 border rounded"/></div></div><div><label className="block text-sm font-bold text-slate-600">Challenges</label><textarea name="challenges" className="w-full p-3 border rounded h-20"></textarea></div></div>)}<div className="mt-8 flex justify-between">{step > 1 && <button type="button" onClick={() => setStep(step - 1)} className="px-6 py-2 text-slate-600 font-bold">Back</button>}<button type="submit" disabled={submitting} className="ml-auto bg-purple-700 text-white px-8 py-3 rounded-lg font-bold shadow hover:bg-purple-800 transition-colors">{submitting ? 'Submitting...' : (step === 3 ? 'Finish & Submit' : 'Next')}</button></div></form></div></div>); };
window.ClientDashboard = ({ name, setView, token }) => { // Note: 'token' added to props
    const Icons = window.Icons;
    const [stats, setStats] = React.useState({ projects: [], invoices: [] });
    const [clientData, setClientData] = React.useState(null); // Store full client profile
    const [showProfile, setShowProfile] = React.useState(false);
    
    React.useEffect(() => {
        // Fetch Billing/Projects Stats
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_billing_overview', token }) })
            .then(r => r.status==='success' && setStats(prev => ({ ...prev, invoices: r.invoices || [] })));
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_projects', token }) })
            .then(r => r.status==='success' && setStats(prev => ({ ...prev, projects: r.projects || [] })));
            
        // We will fetch profile on demand when opening the modal
    }, [token]);

    const handleEditProfile = async () => {
        // Try to fetch self profile for better prefill
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_my_profile', token }) });
        if (res.status === 'success') {
            setClientData(res.profile || { full_name: name, business_name: '', phone: '', website: '', address: '', position: '' });
        } else {
            setClientData({ full_name: name, business_name: '', phone: '', website: '', address: '', position: '' });
        }
        setShowProfile(true);
    };

    return (
        <div className="space-y-6 animate-fade-in">
            <window.FirstMate stats={stats.invoices} projects={stats.projects} token={token} role="client" title="SECOND MATE AI" />
            
            <div className="bg-[#2c3259] text-white p-8 rounded-2xl shadow-lg flex justify-between items-center">
                <div>
                    <h2 className="text-3xl font-bold">Welcome, {(name || 'Client').split(' ')[0]}!</h2>
                    <p className="text-white/60 text-sm mt-1">Manage your projects and billing.</p>
                </div>
                <button onClick={handleEditProfile} className="bg-white/10 hover:bg-white/20 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 transition-colors">
                    <Icons.Settings size={16}/> My Profile
                </button>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div onClick={() => setView('projects')} className="bg-white p-6 rounded-xl border shadow-sm cursor-pointer hover:border-[#2493a2]">
                    <div className="flex items-center gap-3 mb-4"><div className="p-3 bg-orange-100 text-orange-600 rounded-lg"><Icons.Folder /></div><h3 className="font-bold text-lg text-slate-800">My Projects</h3></div>
                    <p className="text-sm text-slate-500">{stats.projects.length} Active</p>
                </div>
                <div onClick={() => setView('billing')} className="bg-white p-6 rounded-xl border shadow-sm cursor-pointer hover:border-[#2493a2]">
                    <div className="flex items-center gap-3 mb-4"><div className="p-3 bg-green-100 text-green-600 rounded-lg"><Icons.CreditCard /></div><h3 className="font-bold text-lg text-slate-800">Billing</h3></div>
                    <p className="text-sm text-slate-500">View Invoices</p>
                </div>
            </div>

            {showProfile && (
                <ClientProfileModal 
                    token={token} 
                    clientData={clientData || { full_name: name, business_name: '', phone: '', website: '', address: '', position: '' }} 
                    onClose={() => setShowProfile(false)} 
                />
            )}
        </div>
    );
};

// =============================================================================
// Wandering Webmaster Custom Component
// Version: 30.2 - Added ClientProfileModal
// =============================================================================

const ClientProfileModal = ({ token, clientData, onClose }) => {
    const Icons = window.Icons;
    const [loading, setLoading] = React.useState(false);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        const f = new FormData(e.target);
        const payload = {
            action: 'client_self_update',
            token: token,
            full_name: f.get('full_name'),
            business_name: f.get('business_name'),
            phone: f.get('phone'),
            website: f.get('website'),
            address: f.get('address'),
            position: f.get('position')
        };
        
        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify(payload) });
        setLoading(false);
        if (res.status === 'success') {
            alert("Profile Updated & Synced to external systems.");
            onClose();
            window.location.reload(); // Refresh to show new name
        } else {
            alert(res.message);
        }
    };

    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm animate-fade-in">
            <div className="bg-white w-full max-w-lg rounded-xl shadow-2xl overflow-hidden">
                <div className="bg-[#2c3259] p-4 flex justify-between items-center text-white">
                    <h3 className="font-bold flex items-center gap-2"><Icons.Settings size={18}/> Edit My Profile</h3>
                    <button onClick={onClose}><Icons.Close/></button>
                </div>
                <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-bold text-slate-500 mb-1">Full Name</label>
                            <input name="full_name" defaultValue={clientData.full_name} className="w-full p-2 border rounded" required/>
                        </div>
                        <div>
                            <label className="block text-xs font-bold text-slate-500 mb-1">Business Name</label>
                            <input name="business_name" defaultValue={clientData.business_name} className="w-full p-2 border rounded" required/>
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-slate-500 mb-1">Phone</label>
                        <input name="phone" defaultValue={clientData.phone} className="w-full p-2 border rounded"/>
                        <p className="text-[10px] text-slate-400 mt-1">This will sync to your billing profile.</p>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-bold text-slate-500 mb-1">Website</label>
                            <input name="website" defaultValue={clientData.website} className="w-full p-2 border rounded"/>
                        </div>
                        <div>
                            <label className="block text-xs font-bold text-slate-500 mb-1">Position</label>
                            <input name="position" defaultValue={clientData.position} className="w-full p-2 border rounded"/>
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-slate-500 mb-1">Address</label>
                        <textarea name="address" defaultValue={clientData.address} className="w-full p-2 border rounded h-20"></textarea>
                    </div>
                    <div className="pt-2">
                        <button disabled={loading} className="w-full bg-[#2c3259] text-white py-3 rounded-lg font-bold shadow hover:bg-[#1e7e8b] transition-colors">
                            {loading ? 'Syncing...' : 'Save & Sync Profile'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

// --- SUPPORT & TICKETING VIEW ---
// --- SUPPORT & TICKETING VIEW ---
window.SupportView = ({ token, role }) => {
    const Icons = window.Icons;
    const [tickets, setTickets] = React.useState([]);
    const [activeTicket, setActiveTicket] = React.useState(null);
    const [showCreate, setShowCreate] = React.useState(false);
    const [loading, setLoading] = React.useState(true);
    const [showClosed, setShowClosed] = React.useState(false); // Default: Hide Closed
    const isAdmin = role === 'admin';

    const fetchTickets = () => {
        window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_tickets', token }) })
        .then(res => { if(res.status==='success') setTickets(res.tickets); })
        .finally(()=>setLoading(false));
    };

    React.useEffect(() => { fetchTickets(); }, [token]);

    // Deep Link Handler
    React.useEffect(() => {
        const handleDeepLink = () => {
            if (tickets.length === 0) return;
            const pending = localStorage.getItem('pending_nav');
            if(pending) {
                const nav = JSON.parse(pending);
                if(nav.view === 'support' && nav.target_id) {
                    const target = tickets.find(t => t.id == nav.target_id);
                    if(target) { 
                        setActiveTicket(target); 
                        localStorage.removeItem('pending_nav'); 
                    }
                }
            }
        };
        
        const handlePendingNav = (e) => {
            if (tickets.length === 0) return;
            const nav = e.detail;
            if(nav && nav.target_id) {
                const target = tickets.find(t => t.id == nav.target_id);
                if(target) { 
                    setActiveTicket(target); 
                    localStorage.removeItem('pending_nav'); 
                }
            }
        };
        
        // Run on mount/update
        handleDeepLink();
        // Listen for global view switches
        window.addEventListener('switch_view', handleDeepLink);
        // Listen for pending nav events
        window.addEventListener('handle_pending_nav', handlePendingNav);
        return () => {
            window.removeEventListener('switch_view', handleDeepLink);
            window.removeEventListener('handle_pending_nav', handlePendingNav);
        };
    }, [tickets]);

    if (loading) return <div className="p-8 text-center"><Icons.Loader/></div>;

    // Filter Logic
    const visibleTickets = tickets.filter(t => showClosed ? true : t.status !== 'closed');

    return (
        <div className="flex flex-col h-[calc(100vh-140px)] animate-fade-in">
            <div className="flex justify-between items-center mb-4">
                <h2 className="text-2xl font-bold text-[#2c3259]">The Bridge (Support)</h2>
                <div className="flex gap-2">
                    <button onClick={()=>setShowClosed(!showClosed)} className={`px-3 py-2 rounded text-xs font-bold border transition-colors ${showClosed ? 'bg-[#dba000] text-white border-[#dba000]' : 'bg-white text-slate-500 border-slate-200'}`}>
                        {showClosed ? 'Hide Closed' : 'View Closed'}
                    </button>
                    <button onClick={()=>setShowCreate(true)} className="bg-[#2c3259] text-white px-4 py-2 rounded font-bold flex items-center gap-2 text-sm">
                        <Icons.Plus size={16}/> New Ticket
                    </button>
                </div>
            </div>

            <div className="flex flex-1 gap-6 overflow-hidden">
                {/* LEFT: TICKET LIST */}
                <div className="w-1/3 bg-white rounded-xl border shadow-sm overflow-y-auto">
                    {visibleTickets.length === 0 ? <div className="p-6 text-slate-400 text-center text-sm">{showClosed ? "No tickets found." : "No active tickets. Check 'View Closed'."}</div> : 
                    visibleTickets.map(t => (
                        <div key={t.id} onClick={()=>setActiveTicket(t)} 
                             className={`p-4 border-b cursor-pointer hover:bg-slate-50 transition-colors ${activeTicket?.id === t.id ? 'bg-blue-50 border-l-4 border-l-[#2493a2]' : ''} ${t.status==='closed' ? 'opacity-60 bg-slate-50' : ''}`}>
                            <div className="flex justify-between items-start mb-1">
                                <span className={`text-[10px] px-2 py-0.5 rounded uppercase font-bold ${t.status==='open'?'bg-green-100 text-green-700':(t.status==='closed'?'bg-slate-200 text-slate-500':'bg-orange-100 text-orange-700')}`}>{t.status}</span>
                                <span className="text-[10px] text-slate-400">{new Date(t.created_at).toLocaleDateString()}</span>
                            </div>
                            <h4 className="font-bold text-slate-800 text-sm mb-1 truncate">{t.subject}</h4>
                            {isAdmin && <p className="text-xs text-[#2c3259] font-bold">{t.client_name}</p>}
                            <p className="text-xs text-slate-500 truncate">{t.last_message || 'No messages yet'}</p>
                        </div>
                    ))}
                </div>

                {/* RIGHT: CONVERSATION THREAD */}
                <div className="flex-1 bg-white rounded-xl border shadow-sm flex flex-col overflow-hidden relative">
                    {activeTicket ? (
                        <TicketThread ticket={activeTicket} token={token} role={role} onUpdate={fetchTickets} />
                    ) : (
                        <div className="flex-1 flex items-center justify-center text-slate-400 flex-col">
                            <Icons.MessageSquare size={48} className="mb-2 opacity-20"/>
                            <p>Select a ticket to view communications</p>
                        </div>
                    )}
                </div>
            </div>

            {showCreate && <CreateTicketModal token={token} role={role} onClose={()=>{setShowCreate(false); fetchTickets();}} />}
        </div>
    );
};

// NEW SUB-COMPONENT: Create Project Modal with AI & Client Search
window.CreateProjectModal = ({ clients = [], onClose, onSubmit, initialData, token, role }) => {
    const Icons = window.Icons;
    const [mode, setMode] = React.useState(initialData?.notes ? 'ai' : 'manual'); // 'manual' or 'ai'
    const [selectedClient, setSelectedClient] = React.useState(null);
    const [searchTerm, setSearchTerm] = React.useState("");
    const [isSearching, setIsSearching] = React.useState(false);
    const [loading, setLoading] = React.useState(false);
    const [clientList, setClientList] = React.useState(clients || []);
    const isAdmin = role === 'admin';

    React.useEffect(() => {
        if (initialData?.client_id && clientList?.length) {
            const pre = clientList.find(c => c.id == initialData.client_id);
            if (pre) { setSelectedClient(pre); setSearchTerm(pre.full_name); }
        }
    }, [initialData, clientList]);

    // Fetch clients internally if not provided and user is admin
    React.useEffect(() => {
        if (isAdmin && (!clientList || clientList.length === 0) && token) {
            window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) })
                .then(res => { if (res.status === 'success') setClientList(res.clients || []); });
        }
    }, [isAdmin, token]);

    const filteredClients = clientList.filter(c => 
        (c.full_name || '').toLowerCase().includes(searchTerm.toLowerCase()) || 
        (c.business_name || '').toLowerCase().includes(searchTerm.toLowerCase())
    ).slice(0, 5);

    const handleSelectClient = (c) => {
        setSelectedClient(c);
        setSearchTerm(c.full_name);
        setIsSearching(false);
    };

    const handleFinalSubmit = async (e) => {
        e.preventDefault();
        if (!selectedClient) return alert("Please select a client.");
        setLoading(true);
        const f = new FormData(e.target);
        if (mode === 'manual') {
            await onSubmit({ 
                action: 'create_project', 
                client_id: selectedClient.id, 
                title: f.get('title') 
            });
        } else {
            await onSubmit({ 
                action: 'ai_create_project', 
                client_id: selectedClient.id, 
                notes: f.get('notes') 
            });
        }
        setLoading(false);
    };

    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50 backdrop-blur-sm">
            <div className="bg-white p-0 rounded-xl w-full max-w-lg relative shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <div className="p-4 bg-[#2c3259] text-white flex justify-between items-center">
                    <h3 className="font-bold text-lg flex items-center gap-2">
                        {mode === 'ai' ? <Icons.Sparkles className="text-[#dba000]"/> : <Icons.Folder/>}
                        {mode === 'ai' ? 'First Mate (AI)' : 'New Project'}
                    </h3>
                    <button onClick={onClose}><Icons.Close/></button>
                </div>
                <div className="flex border-b bg-slate-50">
                    <button onClick={()=>setMode('manual')} className={`flex-1 py-3 text-sm font-bold transition-colors ${mode==='manual' ? 'bg-white text-[#2c3259] border-t-2 border-t-[#2c3259]' : 'text-slate-400 hover:text-slate-600'}`}>Manual Setup</button>
                    <button onClick={()=>setMode('ai')} className={`flex-1 py-3 text-sm font-bold transition-colors ${mode==='ai' ? 'bg-white text-[#2c3259] border-t-2 border-t-[#dba000]' : 'text-slate-400 hover:text-slate-600'}`}>Give to First Mate 🤖</button>
                </div>

                <form onSubmit={handleFinalSubmit} className="p-6 space-y-6 flex-1 overflow-y-auto">
                    <div className="relative">
                        <label className="block text-xs font-bold text-slate-500 mb-1 uppercase">Client</label>
                        <div className="relative">
                            <input 
                                type="text" 
                                className={`w-full p-3 pl-9 border rounded-lg ${selectedClient ? 'bg-green-50 border-green-300 text-green-800 font-bold' : ''}`}
                                placeholder="Type to search client..."
                                value={searchTerm}
                                onChange={(e) => { setSearchTerm(e.target.value); setSelectedClient(null); setIsSearching(true); }}
                                onFocus={() => setIsSearching(true)}
                                required
                            />
                            <div className="absolute left-3 top-3.5 text-slate-400"><Icons.Search size={16}/></div>
                            {selectedClient && <div className="absolute right-3 top-3.5 text-green-600"><Icons.Check size={16}/></div>}
                        </div>
                        {isSearching && searchTerm && !selectedClient && (
                            <div className="absolute z-50 w-full bg-white border rounded-lg shadow-xl mt-1 max-h-48 overflow-y-auto">
                                {filteredClients.map(c => (
                                    <div key={c.id} onClick={() => handleSelectClient(c)} className="p-3 border-b last:border-0 hover:bg-slate-50 cursor-pointer">
                                        <div className="font-bold text-sm text-[#2c3259]">{c.full_name}</div>
                                        <div className="text-xs text-slate-500">{c.business_name || 'No Business Name'}</div>
                                    </div>
                                ))}
                                {filteredClients.length === 0 && <div className="p-3 text-xs text-slate-400 italic">No clients found.</div>}
                            </div>
                        )}
                    </div>

                    {mode === 'manual' ? (
                        <div className="animate-fade-in">
                            <label className="block text-xs font-bold text-slate-500 mb-1 uppercase">Project Title</label>
                            <input name="title" className="w-full p-3 border rounded-lg" placeholder="e.g. Website Redesign" required />
                        </div>
                    ) : (
                        <div className="space-y-4 animate-fade-in">
                            <div className="bg-blue-50 p-4 rounded-lg border border-blue-100 text-sm text-blue-800">
                                <p className="font-bold mb-1"><Icons.Sparkles size={14} className="inline mr-1"/> First Mate Protocol:</p>
                                I will analyze your notes to create the Project Title, Scope Description, and an initial Task List with priorities automatically.
                            </div>
                            <div>
                                <label className="block text-xs font-bold text-slate-500 mb-1 uppercase">Project Brief / Notes</label>
                                <textarea name="notes" defaultValue={initialData?.notes || ''} className="w-full p-3 border rounded-lg h-32 focus:ring-2 focus:ring-[#dba000] outline-none" placeholder="e.g. Need a 5-page site for a dentist. Needs booking form, gallery, and SEO setup. High priority on mobile." required></textarea>
                            </div>
                        </div>
                    )}

                    <button disabled={loading} className={`w-full p-4 rounded-xl font-bold text-white shadow-lg transition-all ${loading ? 'bg-slate-400' : (mode==='ai' ? 'bg-gradient-to-r from-[#2c3259] to-[#2493a2] hover:opacity-90' : 'bg-[#2c3259]')}`}>
                        {loading ? <span className="flex items-center justify-center gap-2"><Icons.Loader className="animate-spin"/> Processing...</span> : (mode === 'ai' ? 'Generate Project Plan' : 'Create Project')}
                    </button>
                </form>
            </div>
        </div>
    );
};
const TicketThread = ({ ticket, token, role, onUpdate }) => {
    const Icons = window.Icons;
    const [messages, setMessages] = React.useState([]);
    const [reply, setReply] = React.useState("");
    const [isInternal, setIsInternal] = React.useState(false);
    const [isThinking, setIsThinking] = React.useState(false);
    const scrollRef = React.useRef(null);
    const isAdmin = role === 'admin';

    // 1. Initial Load & Event Listener for "Typing"
    React.useEffect(() => {
        const loadThread = async () => {
            const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_ticket_thread', token, ticket_id: ticket.id }) });
            if(res.status==='success') setMessages(res.messages);
        };
        
        // Listen for AI Typing Events
        const handleNewAiMsg = (e) => {
            const newMsg = e.detail;
            setMessages(prev => {
                // Avoid duplicates
                if (prev.some(m => m.id === newMsg.id)) return prev;
                return [...prev, newMsg];
            });
            // Scroll on new message
            if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
        };

        window.addEventListener('new_ai_msg', handleNewAiMsg);
        loadThread(); // Initial load

        return () => window.removeEventListener('new_ai_msg', handleNewAiMsg);
    }, [ticket.id]);

    // 2. Polling for Admin Replies (Every 4s)
    React.useEffect(() => {
        const poll = setInterval(async () => {
            if (isThinking) return; // Don't poll while waiting for AI to reply
            
            const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_ticket_thread', token, ticket_id: ticket.id }) });
            if (res.status === 'success') {
                setMessages(prev => {
                    // Only update if we have MORE messages (simple append check)
                    if (res.messages.length > prev.length) {
                        // If the new message is System/AI, let simulateTyping handle it? 
                        // Ideally yes, but for simple polling, just update is fine.
                        return res.messages; 
                    }
                    return prev;
                });
            }
        }, 4000);
        return () => clearInterval(poll);
    }, [ticket.id, isThinking]);

    // Scroll effect
    React.useEffect(() => { if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight; }, [messages]);

    const handleSend = async (e) => {
        e.preventDefault();
        if(!reply.trim()) return;
        
        const textToSend = reply;
        const tempId = 'temp-' + Date.now();
        setReply(""); 
        
        // Optimistic Update (User Msg)
        const tempMsg = {
            id: tempId,
            role: role, 
            sender_id: 99999, 
            message: textToSend,
            created_at: new Date().toISOString()
        };
        setMessages(prev => [...prev, tempMsg]);
        setIsThinking(true);

        try {
            // Send
            const sendRes = await window.safeFetch(API_URL, { 
                method: 'POST', 
                body: JSON.stringify({ action: 'reply_ticket', token, ticket_id: ticket.id, message: textToSend, is_internal: isInternal }) 
            });

            if (sendRes.status !== 'success') {
                if (sendRes.message.includes('closed')) {
                    alert("Ticket Closed");
                    if(onUpdate) onUpdate();
                    return;
                }
                throw new Error(sendRes.message);
            }

            // Update Status
            if (sendRes.new_status && sendRes.new_status !== ticket.status) {
                ticket.status = sendRes.new_status;
                if (onUpdate) onUpdate();
            }

            // FETCH NEW THREAD BUT DO NOT RENDER YET
            const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_ticket_thread', token, ticket_id: ticket.id }) });
            
            if(res.status==='success') {
                const allMsgs = res.messages;
                // Find what the backend added (AI replies)
                // Logic: Messages that have sender_id=0 and ID > any message we currently have (excluding temp)
                const realMsgs = messages.filter(m => m.id !== tempId);
                const lastRealId = realMsgs.length > 0 ? realMsgs[realMsgs.length - 1].id : 0;
                
                const newAiMsgs = allMsgs.filter(m => m.id > lastRealId && m.sender_id == 0);
                const newHumanMsgs = allMsgs.filter(m => m.id > lastRealId && m.sender_id != 0);

                // 1. Set state to "History + User Message (Confirmed)"
                // We replace the temp message with the real one from server if possible, or just use the full list minus AI stuff
                const nonAiList = allMsgs.filter(m => m.sender_id != 0 || m.id <= lastRealId);
                setMessages(nonAiList);

                // 2. Trigger Theatrical Typing for AI messages
                if (newAiMsgs.length > 0) {
                    window.simulateTyping(newAiMsgs, 50, () => {
                        setIsThinking(false);
                        // Final sync to ensure nothing missed
                        setMessages(allMsgs); 
                    });
                } else {
                    setIsThinking(false);
                    setMessages(allMsgs);
                }
            }
        } catch (err) {
            console.error(err);
            alert("Error: " + err.message);
            setIsThinking(false);
        }
    };
    
    const handleClose = async () => {
         if(!confirm("Close ticket?")) return;
         await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'update_ticket_status', token, ticket_id: ticket.id, status: 'closed' }) });
         if(onUpdate) onUpdate();
    };

    const handleConvertToProject = () => {
        const history = messages.map(m => `${m.full_name || (m.sender_id===0?'System':'Unknown')}: ${m.message}`).join('\n');
        const brief = `SOURCE TICKET: ${ticket.subject}\n\nCONTEXT:\n${history}`;
        window.dispatchEvent(new CustomEvent('open_project_modal', { detail: { client_id: ticket.user_id, notes: brief } }));
    };

    const handleReopen = async () => {
         if(!confirm("Re-open ticket?")) return;
         await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'update_ticket_status', token, ticket_id: ticket.id, status: 'open' }) });
         if(onUpdate) onUpdate();
    };

    return (
        <>
            <div className="p-4 border-b bg-slate-50 flex justify-between items-center">
                <div>
                    <h3 className="font-bold text-lg text-[#2c3259]">{ticket.subject}</h3>
                    <p className="text-xs text-slate-500">Ticket #{ticket.id} • Priority: <span className="uppercase">{ticket.priority}</span></p>
                </div>
                <div className="flex gap-2 items-center">
                    {isAdmin && <button onClick={handleConvertToProject} className="text-xs px-3 py-1 rounded bg-indigo-600 text-white hover:bg-indigo-700">Generate Project</button>}
                    {(isAdmin || role === 'client') && ticket.status !== 'closed' && <button onClick={handleClose} className="text-xs border border-slate-300 px-3 py-1 rounded hover:bg-slate-200">Close Ticket</button>}
                </div>
            </div>

            <div className="flex-1 overflow-y-auto p-6 space-y-4" ref={scrollRef}>
                {messages.map(m => {
                    const isSystem = m.sender_id == 0;
                    const isInternalMsg = m.is_internal == 1;
                    let bubbleColor = 'bg-slate-100 text-slate-600';
                    
                    const text = m.message || '';
                    let displayMessage = text;
                    let personaLabel = null;

                    if (isInternalMsg) {
                        bubbleColor = 'bg-yellow-100 border-yellow-200 text-yellow-900';
                        personaLabel = 'Internal Note';
                    } else if (isSystem) {
                        if (text.includes('[First Mate]')) {
                            bubbleColor = 'bg-indigo-100 border-indigo-200 text-indigo-900';
                            displayMessage = text.replace('[First Mate]', '').trim();
                            personaLabel = 'First Mate (Senior AI)';
                        } else if (text.includes('[Second Mate]')) {
                            bubbleColor = 'bg-teal-50 border-teal-200 text-teal-800';
                            displayMessage = text.replace('[Second Mate]', '').trim();
                            personaLabel = 'Second Mate';
                        } else if (text.includes('[System]')) {
                            bubbleColor = 'bg-slate-200 text-slate-600 text-xs font-bold text-center py-1';
                            displayMessage = text.replace('[System]', '').trim();
                            personaLabel = null; 
                        }
                    } else {
                        bubbleColor = (m.role === 'admin' ? 'bg-[#2c3259] text-white' : 'bg-[#2493a2] text-white');
                    }
                    
                    const align = isSystem ? 'justify-start' : (m.role === role ? 'justify-end' : 'justify-start');
                    
                    if (isSystem && text.includes('[System]')) {
                        return (
                            <div key={m.id} className="flex justify-center my-2">
                                <span className="bg-slate-100 text-slate-500 text-[10px] uppercase font-bold px-3 py-1 rounded-full border">{displayMessage}</span>
                            </div>
                        );
                    }

                    return (
                        <div key={m.id} className={`flex ${align}`}>
                            <div className={`max-w-[85%] p-3 rounded-xl text-sm shadow-sm relative ${bubbleColor}`}>
                                {personaLabel && <div className="text-[9px] font-bold uppercase opacity-70 mb-1 tracking-wider">{personaLabel}</div>}
                                <span className="whitespace-pre-wrap block leading-relaxed">{window.formatTextWithLinks(displayMessage)}</span>
                                <div className="text-[9px] mt-2 opacity-50 text-right">{new Date(m.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                            </div>
                        </div>
                    );
                })}
                {isThinking && (
                     <div className="flex justify-start animate-pulse">
                        <div className="bg-teal-50/50 border border-teal-100 text-teal-600 p-2 rounded-lg flex items-center">
                            <window.Icons.Sparkles size={18} className="animate-spin"/> 
                        </div>
                    </div>
                )}
            </div>

            {ticket.status === 'closed' ? (
                <div className="p-4 border-t bg-slate-50 text-center text-slate-500 text-sm font-bold flex items-center justify-center gap-2">
                    <window.Icons.Lock size={16}/> This ticket is closed.
                    {(isAdmin || role === 'client') && (
                        <button onClick={() => handleReopen()} className="text-blue-600 hover:underline ml-2">Re-open</button>
                    )}
                </div>
            ) : (
                <form onSubmit={handleSend} className="p-4 border-t bg-white">
                    {isAdmin && (
                        <div className="flex items-center gap-2 mb-2">
                            <input type="checkbox" checked={isInternal} onChange={e=>setIsInternal(e.target.checked)} id="internal_note" className="w-4 h-4 text-[#2c3259]"/>
                            <label htmlFor="internal_note" className="text-xs font-bold text-slate-500 cursor-pointer select-none">Internal Note (Client won't see this)</label>
                        </div>
                    )}
                    <div className="flex gap-2">
                        <input 
                            value={reply} 
                            onChange={e=>setReply(e.target.value)} 
                            disabled={ticket.status === 'closed'}
                            className={`flex-1 p-3 border rounded-lg focus:outline-none focus:ring-2 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed ${isInternal ? 'focus:ring-yellow-400 bg-yellow-50' : 'focus:ring-[#2493a2]'}`} 
                            placeholder={isInternal ? "Add an internal note..." : "Type your reply..."}
                        />
                        <button disabled={ticket.status === 'closed'} className="bg-[#2c3259] text-white p-3 rounded-lg disabled:bg-slate-300 disabled:cursor-not-allowed hover:bg-[#1a1a3a] disabled:hover:bg-slate-300"><Icons.Send size={18}/></button>
                    </div>
                </form>
            )}
        </>
    );
};

const CreateTicketModal = ({ token, onClose, role }) => {
    const Icons = window.Icons;
    const [subject, setSubject] = React.useState("");
    const [suggestion, setSuggestion] = React.useState(null);
    const [clients, setClients] = React.useState([]); 
    const [selectedClient, setSelectedClient] = React.useState(null);
    const isAdmin = role === 'admin';
    
    // Search State
    const [searchTerm, setSearchTerm] = React.useState("");
    const [isSearching, setIsSearching] = React.useState(false);

    // Helper to convert URLs to clickable links
    const formatTextWithLinks = (text) => {
        if (!text) return null;
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        const parts = text.split(urlRegex);
        return parts.map((part, i) => 
            urlRegex.test(part) ? 
            <a key={i} href={part} target="_blank" rel="noopener noreferrer" className="text-blue-600 underline font-bold break-all hover:text-blue-800">{part}</a> 
            : part
        );
    };

    // Fetch Clients only if Admin
    React.useEffect(() => {
        if (isAdmin) {
            window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_clients', token }) })
                .then(res => { if (res.status === 'success') setClients(res.clients || []); });
        }
    }, [isAdmin, token]);

    // Smart Suggestion
    React.useEffect(() => {
        const timer = setTimeout(async () => {
            if (subject.length > 10) {
                const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'suggest_solution', token, subject }) });
                if (res.status === 'success' && res.match) setSuggestion(res.text); else setSuggestion(null);
            }
        }, 1000);
        return () => clearTimeout(timer);
    }, [subject]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (isAdmin && !selectedClient) return alert("Please select a client.");
        
        const f = new FormData(e.target);
        const payload = { 
            action: 'create_ticket', token, 
            subject: f.get('subject'), 
            message: f.get('message'), 
            priority: f.get('priority') 
        };
        if (isAdmin && selectedClient) payload.target_client_id = selectedClient.id;

        // Show immediate feedback
        const btn = e.target.querySelector('button');
        const originalText = btn.innerText;
        btn.innerText = "Opening secure channel...";
        btn.disabled = true;

        const res = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify(payload) });
        
        if (res.status === 'success') {
            onClose();
            // 1. Set navigation target
            const navData = { view: 'support', target_id: res.ticket_id };
            localStorage.setItem('pending_nav', JSON.stringify(navData));
            
            // 2. Simulate "Thinking" delay for the very first message
            // We set a flag so TicketThread knows to show the spinner
            localStorage.setItem('ai_thinking_' + res.ticket_id, 'true');

            // 3. Switch View
            window.dispatchEvent(new CustomEvent('switch_view', { detail: 'support' }));
        } else {
            alert(res.message);
            btn.innerText = originalText;
            btn.disabled = false;
        }
    };

    // Filter Logic
    const filteredClients = clients.filter(c => 
        (c.full_name || '').toLowerCase().includes(searchTerm.toLowerCase()) || 
        (c.business_name || '').toLowerCase().includes(searchTerm.toLowerCase())
    ).slice(0, 5);

    const handleSelectClient = (c) => {
        setSelectedClient(c);
        setSearchTerm(c.full_name);
        setIsSearching(false);
    };

    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50 backdrop-blur-sm">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-lg relative overflow-hidden animate-fade-in flex flex-col max-h-[90vh]">
                <div className="p-6 border-b bg-[#2c3259] text-white flex justify-between items-center">
                    <h3 className="font-bold text-lg">Open New Ticket</h3>
                    <button onClick={onClose}><Icons.Close/></button>
                </div>
                
                <form onSubmit={handleSubmit} className="p-6 space-y-4 flex-1 overflow-y-auto">
                    {/* ADMIN: SMART CLIENT SELECTOR */}
                    {isAdmin && (
                        <div className="relative">
                            <label className="block text-xs font-bold text-slate-500 mb-1 uppercase">Opening Ticket For</label>
                            <div className="relative">
                                <input 
                                    type="text" 
                                    className={`w-full p-3 pl-9 border rounded-lg ${selectedClient ? 'bg-green-50 border-green-300 text-green-800 font-bold' : ''}`}
                                    placeholder="Type to search client..."
                                    value={searchTerm}
                                    onChange={(e) => { setSearchTerm(e.target.value); setSelectedClient(null); setIsSearching(true); }}
                                    onFocus={() => setIsSearching(true)}
                                    required
                                />
                                <div className="absolute left-3 top-3.5 text-slate-400"><Icons.Search size={16}/></div>
                                {selectedClient && <div className="absolute right-3 top-3.5 text-green-600"><Icons.Check size={16}/></div>}
                            </div>
                            
                            {isSearching && searchTerm && !selectedClient && (
                                <div className="absolute z-50 w-full bg-white border rounded-lg shadow-xl mt-1 max-h-48 overflow-y-auto">
                                    {filteredClients.map(c => (
                                        <div key={c.id} onClick={() => handleSelectClient(c)} className="p-3 border-b last:border-0 hover:bg-slate-50 cursor-pointer">
                                            <div className="font-bold text-sm text-[#2c3259]">{c.full_name}</div>
                                            <div className="text-xs text-slate-500">{c.business_name || 'No Business Name'}</div>
                                        </div>
                                    ))}
                                    {filteredClients.length === 0 && <div className="p-3 text-xs text-slate-400 italic">No clients found.</div>}
                                </div>
                            )}
                        </div>
                    )}

                    <div>
                        <label className="block text-xs font-bold text-slate-500 mb-1 uppercase">Subject</label>
                        <input name="subject" value={subject} onChange={e=>setSubject(e.target.value)} className="w-full p-3 border rounded-lg focus:ring-2 focus:ring-[#2493a2] outline-none" placeholder="Briefly describe the issue..." required />
                    </div>

                    {suggestion && (
                        <div className="bg-blue-50 border border-blue-200 p-4 rounded-lg text-sm text-blue-800">
                            <div className="flex items-center gap-2 font-bold mb-1"><Icons.Sparkles size={14}/> Suggestion found:</div>
                            <p className="whitespace-pre-wrap">{formatTextWithLinks(suggestion)}</p>
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-bold text-slate-500 mb-1 uppercase">Priority</label>
                            <select name="priority" className="w-full p-3 border rounded-lg">
                                <option value="low">Low</option>
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-500 mb-1 uppercase">Message</label>
                        <textarea name="message" className="w-full p-3 border rounded-lg h-32 focus:ring-2 focus:ring-[#2493a2] outline-none" placeholder="Please provide details..." required></textarea>
                    </div>

                    <div className="pt-2">
                        <button className="w-full bg-[#2c3259] hover:bg-[#1e7e8b] text-white p-3 rounded-lg font-bold shadow-lg transition-colors">
                            {isAdmin && selectedClient ? `Submit for ${selectedClient.full_name}` : "Submit Ticket"}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

// ============================================================================
// STANDALONE DEBUG PANEL - Completely independent system
// ============================================================================
window.StandaloneDebugPanel = ({ token }) => {
    const [logs, setLogs] = React.useState([]);
    const [loading, setLoading] = React.useState(false);
    const [lastUpdate, setLastUpdate] = React.useState(null);
    const Icons = window.Icons;
    const API_URL = '/api/portal_api.php';

    // Load logs immediately on mount
    React.useEffect(() => {
        loadLogs();
    }, []);

    const loadLogs = async () => {
        setLoading(true);
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_system_logs', token })
            });
            const responseText = await response.text();
            console.log('[StandaloneDebug] Raw response:', responseText);
            const data = JSON.parse(responseText);
            console.log('[StandaloneDebug] Logs loaded:', data);
            
            if (data.status === 'success' && data.logs) {
                setLogs(data.logs);
                setLastUpdate(new Date().toLocaleTimeString());
            } else {
                setLogs([{
                    id: -999,
                    level: 'error',
                    message: 'Failed to load logs: ' + (data.message || 'Unknown error'),
                    created_at: new Date().toISOString(),
                    source: 'debug_panel'
                }]);
            }
        } catch (error) {
            console.error('[StandaloneDebug] Error:', error);
            setLogs([{
                id: -1000,
                level: 'error',
                message: 'CRITICAL ERROR: ' + error.message,
                created_at: new Date().toISOString(),
                source: 'debug_panel'
            }]);
        }
        setLoading(false);
    };

    const runTest = async (testName, testLabel) => {
        try {
            console.log(`[StandaloneDebug] Running test: ${testName}`);
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'debug_test', token, test: testName })
            });
            const responseText = await response.text();
            console.log(`[StandaloneDebug] Raw response for ${testName}:`, responseText);
            const data = JSON.parse(responseText);
            console.log(`[StandaloneDebug] Test result:`, data);
            
            // Add result to logs immediately
            const newLog = {
                id: Date.now(),
                level: data.status === 'success' ? 'success' : 'error',
                message: `[${testLabel}] ${data.message || JSON.stringify(data)}`,
                created_at: new Date().toISOString(),
                source: 'test_' + testName
            };
            setLogs(prev => [newLog, ...prev]);
            
            // Reload all logs after a moment
            setTimeout(loadLogs, 500);
        } catch (error) {
            console.error(`[StandaloneDebug] Test failed:`, error);
            setLogs(prev => [{
                id: Date.now(),
                level: 'error',
                message: `[${testLabel}] ERROR: ${error.message}`,
                created_at: new Date().toISOString(),
                source: 'test_error'
            }, ...prev]);
        }
    };

    const logManualEvent = async () => {
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'debug_log', 
                    token, 
                    message: '[Manual Test] Admin clicked debug button at ' + new Date().toLocaleTimeString()
                })
            });
            const data = await response.json();
            console.log('[StandaloneDebug] Manual log:', data);
            setTimeout(loadLogs, 500);
        } catch (error) {
            console.error('[StandaloneDebug] Manual log failed:', error);
        }
    };

    return (
        <div className="space-y-4 animate-fade-in">
            {/* Status Bar */}
            <div className="bg-green-50 border border-green-200 rounded-lg p-3 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <div className="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
                    <span className="font-bold text-green-900">Standalone Debug System Active</span>
                </div>
                {lastUpdate && (
                    <span className="text-xs text-green-600">Last update: {lastUpdate}</span>
                )}
            </div>

            {/* Debug Buttons */}
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div className="font-bold text-blue-900 mb-3 flex items-center gap-2">
                    <Icons.Activity size={18}/>
                    Common Debugging Checks
                </div>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
                    <button 
                        onClick={() => runTest('check_php_errors', 'PHP Errors')}
                        className="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-xs font-bold transition-colors"
                    >
                        🚨 PHP Errors
                    </button>
                    <button 
                        onClick={() => runTest('database_status', 'Database')}
                        className="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-xs font-bold transition-colors"
                    >
                        🔌 Database
                    </button>
                    <button 
                        onClick={() => runTest('api_connection', 'API Status')}
                        className="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-xs font-bold transition-colors"
                    >
                        ✓ API Status
                    </button>
                    <button 
                        onClick={() => runTest('check_json_output', 'JSON Output')}
                        className="bg-cyan-600 hover:bg-cyan-700 text-white px-3 py-2 rounded text-xs font-bold transition-colors"
                    >
                        📋 JSON Output
                    </button>
                    <button 
                        onClick={() => runTest('permissions_audit', 'File Perms')}
                        className="bg-orange-600 hover:bg-orange-700 text-white px-3 py-2 rounded text-xs font-bold transition-colors"
                    >
                        📁 File Perms
                    </button>
                    <button 
                        onClick={() => runTest('check_includes', 'File Includes')}
                        className="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded text-xs font-bold transition-colors"
                    >
                        📦 Includes
                    </button>
                    <button 
                        onClick={() => runTest('rebuild_partners', 'Rebuild Data')}
                        className="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded text-xs font-bold transition-colors"
                    >
                        🔄 Rebuild Data
                    </button>
                    <button 
                        onClick={logManualEvent}
                        className="bg-slate-600 hover:bg-slate-700 text-white px-3 py-2 rounded text-xs font-bold transition-colors"
                    >
                        📝 Test Log
                    </button>
                </div>
            </div>

            {/* Log Display */}
            <div className="bg-slate-900 text-green-400 p-4 rounded-xl font-mono text-xs h-[500px] overflow-y-auto">
                <div className="flex justify-between border-b border-slate-700 pb-2 mb-2 sticky top-0 bg-slate-900">
                    <span className="font-bold text-white">System Logs</span>
                    <span className="text-slate-400 text-[10px]">
                        {logs.length} {logs.length === 1 ? 'entry' : 'entries'}
                        {loading && ' • Loading...'}
                    </span>
                </div>
                
                {logs.length === 0 ? (
                    <div className="text-slate-500 italic text-center py-8">
                        No logs found. Click a button above to run diagnostics.
                    </div>
                ) : (
                    logs.map((log, idx) => (
                        <div 
                            key={`${log.id}-${idx}`} 
                            className="mb-1 border-b border-slate-800 pb-1 last:border-0 hover:bg-slate-800 px-1 transition-colors"
                        >
                            <span className="text-slate-500 mr-2">
                                [{new Date(log.created_at).toLocaleTimeString()}]
                            </span>
                            <span className={`uppercase font-bold mr-2 ${
                                log.level === 'error' ? 'text-red-500' :
                                log.level === 'success' ? 'text-green-500' :
                                log.level === 'warning' ? 'text-yellow-400' :
                                'text-blue-400'
                            }`}>
                                {log.level}
                            </span>
                            <span>{log.message}</span>
                            {log.source && (
                                <span className="text-slate-600 ml-2 text-[10px]">
                                    ({log.source})
                                </span>
                            )}
                        </div>
                    ))
                )}
            </div>
        </div>
    );
};