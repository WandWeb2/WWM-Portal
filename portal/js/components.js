/* =============================================================================
   WandWeb Components - Refined UI & Formatting
   ============================================================================= */

// 1. Backgrounds
window.PortalBackground = () => <div className="fixed inset-0 bg-slate-100 -z-50"/>;

// 2. Global Icons
const Icon = ({ children, ...props }) => (<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...props}>{children}</svg>);

window.Icons = {
    Menu: (p) => <Icon {...p}><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></Icon>,
    Close: (p) => <Icon {...p}><path d="M18 6 6 18"/><path d="m6 6 12 12"/></Icon>,
    Users: (p) => <Icon {...p}><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></Icon>,
    Home: (p) => <Icon {...p}><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></Icon>,
    Folder: (p) => <Icon {...p}><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></Icon>,
    CreditCard: (p) => <Icon {...p}><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></Icon>,
    Sparkles: (p) => <Icon {...p}><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></Icon>,
    LogOut: (p) => <Icon {...p}><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></Icon>,
    Trash: (p) => <Icon {...p}><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></Icon>,
        Archive: (p) => <Icon {...p}><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" x2="14" y1="12" y2="12"/></Icon>,
    Send: (p) => <Icon {...p}><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></Icon>,
    Edit: (p) => <Icon {...p}><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></Icon>,
    MessageSquare: (p) => <Icon {...p}><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></Icon>,
    File: (p) => <Icon {...p}><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></Icon>,
    Download: (p) => <Icon {...p}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></Icon>,
    Upload: (p) => <Icon {...p}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></Icon>,
    Cloud: (p) => <Icon {...p}><path d="M17.5 19c0-1.7-1.3-3-3-3h-11a4 4 0 1 1 .9-7.9 4 4 0 0 1 7.4.2A4 4 0 0 1 17.5 19z"/></Icon>,
    Link: (p) => <Icon {...p}><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></Icon>,
    ExternalLink: (p) => <Icon {...p}><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></Icon>,
    DollarSign: (p) => <Icon {...p}><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></Icon>,
    ShoppingBag: (p) => <Icon {...p}><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></Icon>,
    Loader: (p) => <Icon {...p} className="animate-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></Icon>,
    Check: (p) => <Icon {...p}><polyline points="20 6 9 17 4 12"/></Icon>,
    Activity: (p) => <Icon {...p}><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></Icon>,
    Clock: (p) => <Icon {...p}><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></Icon>,
    Tag: (p) => <Icon {...p}><path d="M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z"/><path d="M7 7h.01"/></Icon>,
    Plus: (p) => <Icon {...p}><path d="M5 12h14"/><path d="M12 5v14"/></Icon>,
    Bell: (p) => <Icon {...p}><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></Icon>,
    Eye: (p) => <Icon {...p}><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></Icon>,
    EyeOff: (p) => <Icon {...p}><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></Icon>,
    Filter: (p) => <Icon {...p}><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></Icon>,
    ArrowDown: (p) => <Icon {...p}><line x1="12" x2="12" y1="5" y2="19"/><polyline points="19 12 12 19 5 12"/></Icon>,
    ArrowUp: (p) => <Icon {...p}><line x1="12" x2="12" y1="19" y2="5"/><polyline points="5 12 12 5 19 12"/></Icon>,
    ChevronDown: (p) => <Icon {...p}><polyline points="6 9 12 15 18 9"/></Icon>,
    ChevronUp: (p) => <Icon {...p}><polyline points="18 15 12 9 6 15"/></Icon>,
    ChevronRight: (p) => <Icon {...p}><polyline points="9 18 15 12 9 6"/></Icon>,
    ChevronLeft: (p) => <Icon {...p}><polyline points="15 18 9 12 15 6"/></Icon>,
    Search: (p) => <Icon {...p}><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></Icon>,
    FileText: (p) => <Icon {...p}><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></Icon>,
    Settings: (p) => <Icon {...p}><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" /></Icon>,
    Anchor: (p) => <Icon {...p}><circle cx="12" cy="5" r="3"/><line x1="12" x2="12" y1="22" y2="8"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/></Icon>
};

// 3. Error Boundary
class ErrorBoundary extends React.Component {
  constructor(props) { 
    super(props); 
    this.state = { hasError: false, error: null }; 
  }
  
  static getDerivedStateFromError(error) { 
    return { hasError: true, error }; 
  }
  
  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-slate-900 text-white p-10 font-mono">
          <div className="max-w-2xl w-full border border-red-500/50 p-6 rounded bg-slate-800">
            <h1 className="text-red-500 font-bold text-xl mb-4">System Critical Error</h1>
            <pre className="bg-black/50 p-4 rounded text-xs text-red-300 overflow-auto mb-6">{this.state.error?.toString()}</pre>
            <button onClick={() => window.location.reload()} className="bg-red-600 px-6 py-2 rounded font-bold hover:bg-red-500 transition-colors">Reload Portal</button>
          </div>
        </div>
      );
    }
    return this.props.children; 
  }
}
window.ErrorBoundary = ErrorBoundary;

// 4. Utils
window.safeFetch = async (url, options) => {
    try {
        const res = await fetch(url, options);
        const text = await res.text();
        try { return JSON.parse(text); } 
        catch (e) { console.error("API Error:", text); return { status: 'error', message: 'Server Error' }; }
    } catch (e) { return { status: 'error', message: 'Network Error' }; }
};

window.formatPhone = (phone) => {
    if (!phone || phone === 'Array' || typeof phone === 'object') return '—';
    return phone;
};

// 5. Shared UI Widgets
window.FilterSortToolbar = ({ filterOptions, filterValue, onFilterChange, sortOrder, onSortToggle, searchValue, onSearchChange }) => (
    <div className="flex flex-col sm:flex-row justify-between items-center mb-3 gap-3">
        <div className="flex items-center gap-3 w-full sm:w-auto">
            <div className="relative flex-1 sm:flex-initial">
                <input type="text" placeholder="Search..." value={searchValue} onChange={e => onSearchChange(e.target.value)} className="pl-8 pr-3 py-1.5 border rounded-lg text-sm w-full sm:w-48 outline-none focus:border-[#2c3259]"/>
                <div className="absolute left-2.5 top-2 text-slate-400"><window.Icons.Search size={14} /></div>
            </div>
            <div className="flex items-center gap-2">
                <span className="text-xs font-bold text-slate-400 uppercase hidden sm:inline">Filter</span>
                <select value={filterValue} onChange={e=>onFilterChange(e.target.value)} className="text-sm p-1.5 border rounded-lg bg-white outline-none focus:border-[#2c3259]">
                    {filterOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
            </div>
        </div>
        <div className="flex items-center gap-2 ml-auto">
            <span className="text-xs font-bold text-slate-400 uppercase hidden sm:block">Sort</span>
            <button onClick={onSortToggle} className="p-1.5 border rounded-lg bg-white text-slate-500 hover:text-[#2c3259] hover:border-[#2c3259] transition-colors flex items-center gap-2 text-sm font-medium px-3">
                {sortOrder === 'newest' || sortOrder === 'highest' ? <window.Icons.ArrowDown size={14}/> : <window.Icons.ArrowUp size={14}/>}
                <span>{sortOrder.charAt(0).toUpperCase() + sortOrder.slice(1)}</span>
            </button>
        </div>
    </div>
);

window.ProductSelector = ({ token, selectedItems, onChange, filterMode = 'all' }) => {
    const [products, setProducts] = React.useState([]); 
    const [loading, setLoading] = React.useState(true);
    
    React.useEffect(() => { 
        window.safeFetch('/api/portal_api.php', { method: 'POST', body: JSON.stringify({ action: 'get_services', token }) })
        .then(res => { 
            if(res && res.status === 'success' && res.data && Array.isArray(res.data.services)) { 
                setProducts(res.data.services); 
            } else { 
                setProducts([]); 
            } 
        })
        .finally(()=>setLoading(false)); 
    }, [token]);
    
    const handleToggle = (price, product) => { 
        const exists = selectedItems.find(i => i.price_id === price.id); 
        if (exists) { 
            onChange(selectedItems.filter(i => i.price_id !== price.id)); 
        } else { 
            onChange([...selectedItems, { price_id: price.id, name: product.name, amount: price.amount, interval: price.interval }]); 
        } 
    };
    
    if (loading) return <div className="p-4 text-center text-xs"><window.Icons.Loader/></div>;
    
    return (
        <div className="border rounded-lg max-h-60 overflow-y-auto bg-slate-50">
            {products.map(prod => { 
                const relevantPrices = prod.prices.filter(p => filterMode === 'all' || (filterMode === 'recurring' && p.interval !== 'one-time')); 
                if (relevantPrices.length === 0) return null; 
                return ( 
                    <div key={prod.id} className="p-3 border-b last:border-0 bg-white"> 
                        <div className="font-bold text-sm text-[#2c3259]">{prod.name}</div> 
                        <div className="flex gap-2 mt-2 flex-wrap"> 
                            {relevantPrices.map(price => { 
                                const isSelected = selectedItems.some(i => i.price_id === price.id); 
                                return ( 
                                    <button key={price.id} type="button" onClick={() => handleToggle(price, prod)} className={`px-3 py-1 rounded-full text-xs font-bold border transition-colors ${isSelected ? 'bg-[#2c3259] text-white border-[#2c3259]' : 'bg-white text-slate-500 border-slate-200 hover:border-[#dba000]'}`}> 
                                        ${price.amount} {price.interval !== 'one-time' ? '/' + price.interval : ''} {isSelected && <span className="ml-1">✓</span>} 
                                    </button> 
                                ); 
                            })} 
                        </div> 
                    </div> 
                ); 
            })}
        </div>
    );
};

// FIRST MATE AI WIDGET (Using new ticket API + Professional Persona)
window.FirstMate = ({ stats = {}, projects = [], token, role = 'admin' }) => {
    const [insight, setInsight] = React.useState("Analyzing portfolio...");
    const [processing, setProcessing] = React.useState(false);
    const Icons = window.Icons;
    const isFirstMate = role === 'admin';
    const accentColor = isFirstMate ? "text-[#2493a2]" : "text-orange-400";

    React.useEffect(() => { 
        const generateInsight = async () => { 
            try {
                const dataContext = { 
                    stats, 
                    projects: Array.isArray(projects) ? projects.map(p => ({ 
                        title: p?.title || 'Untitled', 
                        status: p?.status || 'unknown', 
                        health: p?.health_score || 0 
                    })) : []
                };
                
                const prompt = `Review this data: ${JSON.stringify(dataContext)}. Provide a 1-sentence executive summary.`;
                
                const res = await window.safeFetch(API_URL, { 
                    method: 'POST', 
                    body: JSON.stringify({ action: 'ai_request', token, prompt, data_context: dataContext }) 
                }); 
                
                if (res.status === 'success' && res.text) setInsight(res.text);
            } catch (e) { setInsight("System Ready."); } 
        }; 
        generateInsight(); 
    }, [stats, projects, token]);

    const handleDeepDive = async () => {
        if(processing) return;
        setProcessing(true);
        // 1. Create a ticket based on this insight to start the thread
        const res = await window.safeFetch(API_URL, { 
            method: 'POST', 
            body: JSON.stringify({ action: 'create_ticket_from_insight', token, insight }) 
        });
        
        if(res.status === 'success') {
            // 2. Set Navigation Target in Local Storage for persistent deep linking
            localStorage.setItem('pending_nav', JSON.stringify({ view: 'support', target_id: res.ticket_id }));
            
            // 3. Trigger view switch
            window.dispatchEvent(new CustomEvent('switch_view', { detail: 'support' }));
        } else {
            alert("Failed to create support thread: " + res.message);
        }
        setProcessing(false);
    };

    return (
        <div className="bg-[#2c3259] text-white rounded-xl shadow-xl border border-slate-600 relative overflow-hidden mb-8 transition-all duration-300 cursor-pointer hover:shadow-2xl hover:border-[#2493a2]" onClick={handleDeepDive}>
            <div className="p-6 relative z-10">
                <div className="flex items-center justify-between">
                    <div className="flex gap-4 items-start">
                         <div className="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center shrink-0 border border-white/20 overflow-hidden">
                            <img src="https://wandweb.co/wp-content/uploads/2022/03/DSmale.png" className="w-full h-full object-cover" alt="AI"/>
                        </div>
                        <div>
                            <div className={`flex items-center gap-2 mb-1 ${accentColor} font-bold text-xs tracking-widest uppercase`}>WandWeb AI</div>
                            <p className="text-lg font-medium font-serif italic opacity-90 leading-relaxed pr-8">"{insight}"</p>
                            <p className={`text-xs ${accentColor} mt-2 font-bold uppercase tracking-wide flex items-center gap-2`}>
                                <Icons.MessageSquare size={14}/> {processing ? "Creating Support Session..." : "Click to Discuss / Escalate"}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

// NOTIFICATION BELL (Updated for Deep Linking and Name Display)
window.NotificationBell = ({ token }) => {
    const [list, setList] = React.useState([]); 
    const [open, setOpen] = React.useState(false); 
    const [unread, setUnread] = React.useState(0);
    const Icons = window.Icons;
    
    const fetchNotifs = async () => { 
        const r = await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'get_notifications', token }) }); 
        if (r && r.status === 'success') { 
            setList(r.notifications || []); 
            setUnread((r.notifications || []).filter(n => n.is_read == 0).length); 
        } 
    };

    React.useEffect(() => { 
        fetchNotifs();
        const i = setInterval(fetchNotifs, 30000); 
        return () => clearInterval(i); 
    }, [token]);
    
    const handleInteract = async (n) => { 
        // 1. Mark Read
        if(n.is_read == 0) {
            await window.safeFetch(API_URL, { method: 'POST', body: JSON.stringify({ action: 'mark_read', token, id: n.id }) }); 
            setUnread(prev => Math.max(0, prev - 1));
        }
        
        // 2. Handle Deep Linking via "Pending Navigation" Pattern
        if (n.target_type && n.target_id) {
            const viewName = n.target_type === 'project' ? 'projects' : n.target_type + 's';
            const navData = { view: viewName, target_id: n.target_id };
            localStorage.setItem('pending_nav', JSON.stringify(navData));
            
            // Trigger global view switch
            window.dispatchEvent(new CustomEvent('switch_view', { detail: navData.view }));
        }
        
        setOpen(false);
    };
    
    return (
        <div className="relative">
            <button onClick={() => setOpen(!open)} className="relative text-[#2c3259] p-2">
                <Icons.Bell size={24}/>
                {unread > 0 && <span className="absolute top-0 right-0 w-4 h-4 bg-red-500 rounded-full text-[10px] text-white flex items-center justify-center font-bold">{unread}</span>}
            </button>
            {open && (
                <div className="absolute right-0 top-12 w-80 bg-white rounded-xl shadow-2xl border overflow-hidden z-50">
                    <div className="p-3 border-b font-bold text-sm bg-slate-50">Notifications</div>
                    <div className="max-h-80 overflow-y-auto">
                        {list.length === 0 ? <div className="p-4 text-center text-slate-400 text-sm">No notifications</div> : list.map(n => (
                            <div key={n.id} onClick={()=>handleInteract(n)} className={`p-3 border-b text-sm cursor-pointer hover:bg-slate-50 transition-colors ${n.is_read==0?'bg-blue-50 border-l-4 border-l-[#2493a2]':''}`}>
                                <div className="text-slate-800 font-medium mb-1">{n.message}</div>
                                <div className="text-[10px] text-slate-400 flex justify-between">
                                    <span>{new Date(n.created_at).toLocaleString()}</span>
                                    {n.target_type && <span className="uppercase text-[#2493a2] font-bold tracking-wider text-[9px]">View {n.target_type}</span>}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};