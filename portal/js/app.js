// =============================================================================
// Wandering Webmaster Custom Component
// Agency: Wandering Webmaster (wandweb.co)
// Client: Portal Architecture
// Version: 28.5
// =============================================================================
// --- VERSION HISTORY ---
// 28.3 - Refactored imports.
// 28.4 - Added "Safe Boot" logic.
// 28.5 - Sanitize: Removed accidental citation artifacts ("cite_start") 
//        to fix ReferenceError.

/* =============================================================================
   WandWeb App Controller
   File: app.js
   ============================================================================= */

// 1. Safe Sidebar Component
const Sidebar = ({ view, setView, role, onLogout, isOpen, onClose }) => {
    // Safety check: Ensure Icons exist
    if (!window.Icons) return null;
    const Icons = window.Icons;
    
    const items = role === 'admin' 
        ? [
            { id: 'dashboard', label: 'Command', icon: 'Home' }, 
            { id: 'billing', label: 'Financials', icon: 'DollarSign' }, 
            { id: 'services', label: 'Services', icon: 'ShoppingBag' }, 
            { id: 'clients', label: 'Clients', icon: 'Users' }, 
            { id: 'projects', label: 'Projects', icon: 'Folder' }, 
            { id: 'files', label: 'Files', icon: 'File' },
            { id: 'support', label: 'Support', icon: 'MessageSquare' },
            { id: 'settings', label: 'Settings', icon: 'Settings' } 
          ]
        : [
            { id: 'dashboard', label: 'Dashboard', icon: 'Home' }, 
            { id: 'projects', label: 'Projects', icon: 'Folder' }, 
            { id: 'files', label: 'Files', icon: 'File' }, 
            { id: 'billing', label: 'Billing', icon: 'CreditCard' }, 
            { id: 'services', label: 'Services', icon: 'ShoppingBag' },
            { id: 'support', label: 'Support', icon: 'MessageSquare' }
          ];

    return (
        <div className={`fixed inset-y-0 left-0 z-40 w-64 bg-[#2c3259] text-white transform transition-transform shadow-2xl h-full ${isOpen ? 'translate-x-0' : '-translate-x-full'} md:translate-x-0 flex flex-col`}>
            <div className="p-6 border-b border-slate-700/50 flex justify-center items-center h-24 relative">
                <img src="https://wandweb.co/wp-content/uploads/2025/11/WEBP-LQ-Logo-with-text-mid-White.webp" alt="WandWeb" className="h-10 object-contain" />
                <button onClick={onClose} className="md:hidden absolute right-4 text-slate-400"><Icons.Close /></button>
            </div>
            <nav className="p-4 space-y-2 flex-1">
                {items.map((item) => { 
                    const IconComponent = typeof item.icon === 'string' ? Icons[item.icon] : (item.icon || Icons.Home);
                    return (
                        <button key={item.id} onClick={() => { setView(item.id); onClose(); }} className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-all ${view === item.id ? 'bg-[#2493a2] shadow-lg text-white' : 'hover:bg-white/10 text-slate-300'}`}>
                            <IconComponent size={18} />
                            <span className="text-sm font-medium">{item.label}</span>
                        </button>
                    ); 
                })}
            </nav>
            <div className="p-4 border-t border-slate-700/50">
                <button onClick={onLogout} className="flex items-center gap-3 px-4 py-3 text-slate-400 hover:text-white w-full transition-colors">
                    <Icons.LogOut size={18} /><span>Sign Out</span>
                </button>
            </div>
        </div>
    );
};

const App = () => {
    // 2. DIAGNOSTIC CHECKS
    // Instead of crashing, we check if the required files loaded.
    if (!window.Icons) {
        return (
            <div className="min-h-screen flex items-center justify-center bg-red-50 p-10">
                <div className="bg-white p-6 rounded shadow border-l-4 border-red-500">
                    <h3 className="font-bold text-red-600">Error: components.js failed</h3>
                    <p className="text-sm text-slate-600">Global Icons are missing. Check your browser console (F12) for syntax errors in <code>js/components.js</code>.</p>
                </div>
            </div>
        );
    }
    if (!window.LoginScreen) {
        return (
            <div className="min-h-screen flex items-center justify-center bg-red-50 p-10">
                <div className="bg-white p-6 rounded shadow border-l-4 border-orange-500">
                    <h3 className="font-bold text-orange-600">Error: views.js failed</h3>
                    <p className="text-sm text-slate-600">LoginScreen is undefined. Check your browser console (F12) for syntax errors in <code>js/views.js</code>.</p>
                </div>
            </div>
        );
    }

    // 3. Import Dependencies (Now Guaranteed to Exist)
    const { 
        Icons, PortalBackground, NotificationBell, PortalNotice,
        LoginScreen, SetPasswordScreen, OnboardingView,
        AdminDashboard, ClientDashboard, 
        ProjectsView, FilesView, BillingView, ServicesView, 
        ClientsView, SettingsView, SupportView
    } = window;

    const [session, setSession] = React.useState(null);
    const [view, setView] = React.useState('dashboard');
    const [mobileMenuOpen, setMobileMenuOpen] = React.useState(false);
    const [inviteToken, setInviteToken] = React.useState(null);

    React.useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('action') === 'set_password') setInviteToken(params.get('token')); 
        else if (params.get('action') === 'onboarding') setInviteToken(params.get('token')); 
        else {
            const stored = localStorage.getItem('wandweb_session');
            if (stored) {
                const s = JSON.parse(stored);
                // Ensure name is present from stored session
                setSession({ ...s, name: s.name });
            }
        }
        
        const switchHandler = (e) => {
            if(e.detail) setView(e.detail);
        };
        window.addEventListener('switch_view', switchHandler);
        return () => window.removeEventListener('switch_view', switchHandler);
    }, []);

    // --- ROUTING ---
    if (inviteToken) { 
        const params = new URLSearchParams(window.location.search); 
        if(params.get('action') === 'onboarding') return <OnboardingView token={inviteToken} />; 
        return <SetPasswordScreen token={inviteToken} />; 
    }
    
    if (!session) return <LoginScreen setSession={setSession} />;
    
    // Main App Layout
    return (
        <div className="min-h-screen flex flex-col md:flex-row relative">
             <div className="md:hidden w-full bg-[#2c3259] text-white p-4 flex justify-between items-center z-50 fixed top-0 shadow-md">
                <img src="https://wandweb.co/wp-content/uploads/2025/11/WEBP-LQ-Logo-with-text-mid-White.webp" className="h-8"/>
                <button onClick={() => setMobileMenuOpen(true)}><Icons.Menu /></button>
             </div>
             
             <Sidebar view={view} setView={setView} role={session.role} onLogout={() => { localStorage.removeItem('wandweb_session'); setSession(null); }} isOpen={mobileMenuOpen} onClose={() => setMobileMenuOpen(false)} />
             
             <main className="flex-1 md:ml-64 p-8 overflow-y-auto min-h-screen relative z-10 pt-20 md:pt-8">
                <header className="mb-10 flex justify-between items-center">
                    <h2 className="text-3xl font-bold text-[#2c3259] capitalize tracking-tight">{view.replace('_', ' ')}</h2>
                    <div className="flex items-center gap-4">
                        <NotificationBell token={session.token} />
                        <div className="hidden md:block text-right">
                            <span className="bg-white border border-slate-200 px-4 py-2 rounded-full text-sm font-medium text-slate-600 shadow-sm">{new Date().toLocaleDateString()}</span>
                        </div>
                    </div>
                </header>

                {/* DYNAMIC VIEW LOADER */}
                {view === 'dashboard' && (
                    session.role === 'admin' 
                    ? <AdminDashboard token={session.token} setView={setView} /> 
                    : <ClientDashboard name={session.name} setView={setView} token={session.token} /> 
                )}
                
                {view === 'projects' && <ProjectsView token={session.token} role={session.role} currentUserId={session.user_id} />}
                {view === 'files' && <FilesView token={session.token} role={session.role} />}
                {view === 'billing' && <BillingView token={session.token} role={session.role} />}
                {view === 'services' && <ServicesView token={session.token} role={session.role} />}
                {view === 'clients' && <ClientsView token={session.token} role={session.role} />}
                {view === 'settings' && <SettingsView token={session.token} role={session.role} />}
                {view === 'support' && <SupportView token={session.token} role={session.role} />}
             </main>
             {PortalNotice ? <PortalNotice /> : null}
             {PortalBackground ? <PortalBackground /> : null}
        </div>
    );
};

// --- ROOT RENDER ---
const root = ReactDOM.createRoot(document.getElementById('root'));
const { ErrorBoundary } = window;

if (ErrorBoundary) {
    root.render(<ErrorBoundary><App /></ErrorBoundary>);
} else {
    // Basic fallback
    root.render(<App />);
}