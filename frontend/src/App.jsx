import React, { useState, useEffect, useRef } from 'react';
import { apiFetch, API_BASE, setUnauthorizedHandler } from './api.js';

const TAB_TITLES = {
  dashboard: 'Vezérlőpult',
  inbox: 'Intelligens Inbox',
  accounts: 'Gmail Fiókok',
  users: 'Felhasználók',
};

export default function App({ user, onLogout, onUserUpdate }) {
  const [activeTab, setActiveTab] = useState('dashboard');
  const [accounts, setAccounts] = useState([]);
  const [emails, setEmails] = useState([]);
  const [stats, setStats] = useState(null);
  const [selectedEmail, setSelectedEmail] = useState(null);
  const [selectedEmailFull, setSelectedEmailFull] = useState(null);
  
  // Loading and error states
  const [loadingAccounts, setLoadingAccounts] = useState(false);
  const [loadingEmails, setLoadingEmails] = useState(false);
  const [loadingStats, setLoadingStats] = useState(false);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [submittingAccount, setSubmittingAccount] = useState(false);
  const [syncingAccountId, setSyncingAccountId] = useState(null);
  const [testingAccountId, setTestingAccountId] = useState(null);
  const [users, setUsers] = useState([]);
  const [loadingUsers, setLoadingUsers] = useState(false);
  const [verifyingUserId, setVerifyingUserId] = useState(null);
  const [deletingUserId, setDeletingUserId] = useState(null);
  const [editingUser, setEditingUser] = useState(null);
  const [userEditForm, setUserEditForm] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  });
  const [userEditErrors, setUserEditErrors] = useState({});
  const [savingUser, setSavingUser] = useState(false);
  
  // Alert banner states
  const [alert, setAlert] = useState(null); // { type: 'success' | 'error', message: '' }

  // Form states
  const [newAccount, setNewAccount] = useState({ email: '', password: '' });
  const [formErrors, setFormErrors] = useState({});

  // Search & Filter states for Inbox
  const [searchQuery, setSearchQuery] = useState('');
  const [filterAccount, setFilterAccount] = useState('');
  const [filterCategory, setFilterCategory] = useState('');
  const [filterSentiment, setFilterSentiment] = useState('');
  const [filterPriority, setFilterPriority] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [composeMode, setComposeMode] = useState(null);
  const [composeForm, setComposeForm] = useState({ to: '', subject: '', body: '' });
  const [sendingCompose, setSendingCompose] = useState(false);
  const [downloadingAttachmentKey, setDownloadingAttachmentKey] = useState(null);
  const activeTabRef = useRef(activeTab);

  useEffect(() => {
    setUnauthorizedHandler(() => {
      onLogout?.();
    });

    return () => setUnauthorizedHandler(null);
  }, [onLogout]);

  // Auto-dismiss alert
  useEffect(() => {
    if (alert) {
      const timer = setTimeout(() => setAlert(null), 5000);
      return () => clearTimeout(timer);
    }
  }, [alert]);

  // Fetch initial data
  useEffect(() => {
    fetchAccounts();
    fetchStats();
    fetchEmails();
  }, []);

  // Keep sync-related data updated (matches backend scheduler interval)
  useEffect(() => {
    activeTabRef.current = activeTab;
  }, [activeTab]);

  useEffect(() => {
    const interval = setInterval(() => {
      fetchAccounts();
      fetchStats();
      if (activeTabRef.current === 'inbox') {
        fetchEmails();
      }
    }, 60000);

    return () => clearInterval(interval);
  }, []);

  // Fetch when filters change
  useEffect(() => {
    if (activeTab === 'inbox') {
      fetchEmails();
    }
  }, [filterAccount, filterCategory, filterSentiment, filterPriority, searchQuery, currentPage]);

  const showNotification = (type, message) => {
    setAlert({ type, message });
  };

  const fetchUsers = async () => {
    setLoadingUsers(true);
    try {
      const res = await apiFetch('/users');
      if (res.ok) {
        const data = await res.json();
        setUsers(data);
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Nem sikerült betölteni a felhasználókat.');
    } finally {
      setLoadingUsers(false);
    }
  };

  const handleVerifyUser = async (entry) => {
    if (entry.email_verified_at) return;

    setVerifyingUserId(entry.id);
    try {
      const res = await apiFetch(`/users/${entry.id}/verify`, { method: 'POST' });
      const data = await res.json();

      if (res.ok) {
        showNotification('success', data.message || 'Felhasználó megerősítve.');
        setUsers((prev) => prev.map((item) => (
          item.id === entry.id ? { ...item, email_verified_at: data.user.email_verified_at } : item
        )));
      } else {
        showNotification('error', data.message || 'Nem sikerült megerősíteni a felhasználót.');
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hiba történt a megerősítés során.');
    } finally {
      setVerifyingUserId(null);
    }
  };

  const handleDeleteUser = async (entry) => {
    if (entry.id === user.id) return;

    if (!confirm(`Biztosan törölni szeretnéd a(z) „${entry.name}” felhasználót?`)) return;

    setDeletingUserId(entry.id);
    try {
      const res = await apiFetch(`/users/${entry.id}`, { method: 'DELETE' });
      const data = await res.json();

      if (res.ok) {
        showNotification('success', data.message || 'Felhasználó törölve.');
        setUsers((prev) => prev.filter((item) => item.id !== entry.id));
      } else {
        showNotification('error', data.message || 'Nem sikerült törölni a felhasználót.');
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hiba történt a törlés során.');
    } finally {
      setDeletingUserId(null);
    }
  };

  const openUserEdit = (entry) => {
    setEditingUser(entry);
    setUserEditForm({
      name: entry.name,
      email: entry.email,
      password: '',
      password_confirmation: '',
    });
    setUserEditErrors({});
  };

  const closeUserEdit = () => {
    setEditingUser(null);
    setUserEditForm({ name: '', email: '', password: '', password_confirmation: '' });
    setUserEditErrors({});
  };

  const handleSaveUser = async (event) => {
    event.preventDefault();
    if (!editingUser) return;

    setSavingUser(true);
    setUserEditErrors({});

    const payload = {
      name: userEditForm.name.trim(),
      email: userEditForm.email.trim(),
    };

    if (userEditForm.password.trim()) {
      payload.password = userEditForm.password;
      payload.password_confirmation = userEditForm.password_confirmation;
    }

    try {
      const res = await apiFetch(`/users/${editingUser.id}`, {
        method: 'PUT',
        body: payload,
      });
      const data = await res.json();

      if (res.ok) {
        showNotification('success', data.message || 'Felhasználó módosítva.');
        setUsers((prev) => prev.map((item) => (
          item.id === editingUser.id ? data.user : item
        )));
        if (editingUser.id === user.id) {
          onUserUpdate?.(data.user);
        }
        closeUserEdit();
      } else if (data.errors) {
        setUserEditErrors(data.errors);
        showNotification('error', data.message || 'Ellenőrizd a megadott adatokat.');
      } else {
        showNotification('error', data.message || 'Nem sikerült módosítani a felhasználót.');
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hiba történt a mentés során.');
    } finally {
      setSavingUser(false);
    }
  };

  const fetchAccounts = async () => {
    setLoadingAccounts(true);
    try {
      const res = await apiFetch('/accounts');
      if (res.ok) {
        const data = await res.json();
        setAccounts(data);
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Nem sikerült betölteni a Gmail fiókokat.');
    } finally {
      setLoadingAccounts(false);
    }
  };

  const fetchStats = async () => {
    setLoadingStats(true);
    try {
      const res = await apiFetch('/emails/stats');
      if (res.ok) {
        const data = await res.json();
        setStats(data);
      }
    } catch (e) {
      console.error(e);
    } finally {
      setLoadingStats(false);
    }
  };

  const fetchEmails = async () => {
    setLoadingEmails(true);
    try {
      const params = new URLSearchParams({
        page: currentPage,
        gmail_account_id: filterAccount,
        category: filterCategory,
        sentiment: filterSentiment,
        priority: filterPriority,
        search: searchQuery
      });
      const res = await apiFetch(`/emails?${params.toString()}`);
      if (res.ok) {
        const data = await res.json();
        setEmails(data.data || []);
        setTotalPages(data.last_page || 1);
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Nem sikerült betölteni a leveleket.');
    } finally {
      setLoadingEmails(false);
    }
  };

  const fetchEmailDetail = async (email) => {
    setSelectedEmail(email);
    setLoadingDetail(true);
    setSelectedEmailFull(null);
    try {
      const res = await apiFetch(`/emails/${email.id}`);
      if (res.ok) {
        const data = await res.json();
        setSelectedEmailFull(data);
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Nem sikerült betölteni a levél részleteit.');
    } finally {
      setLoadingDetail(false);
    }
  };

  const handleAddAccount = async (e) => {
    e.preventDefault();
    setFormErrors({});
    setSubmittingAccount(true);
    try {
      const res = await apiFetch('/accounts', {
        method: 'POST',
        body: newAccount,
      });
      
      const data = await res.json();
      
      if (res.ok) {
        showNotification('success', data.message || 'Fiók sikeresen hozzáadva!');
        setNewAccount({ email: '', password: '' });
        fetchAccounts();
        fetchStats();
        fetchEmails();
      } else {
        if (data.errors) {
          setFormErrors(data.errors);
        } else {
          showNotification('error', data.message || 'Kapcsolódási hiba.');
        }
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hálózati hiba a fiók hozzáadása során.');
    } finally {
      setSubmittingAccount(false);
    }
  };

  const extractEmailAddress = (from) => {
    const match = from?.match(/<([^>]+)>/);
    return match ? match[1].trim() : (from || '').trim();
  };

  const buildReplySubject = (subject) => {
    const value = (subject || '').trim();
    if (!value) return 'Re: Üzenet';
    if (/^(re|válasz)\s*:/iu.test(value)) return value;
    return `Re: ${value}`;
  };

  const buildForwardSubject = (subject) => {
    const value = (subject || '').trim();
    if (!value) return 'Fwd: Üzenet';
    if (/^(fw|fwd|forward|továbbítás|továbbított)\s*:/iu.test(value)) return value;
    return `Fwd: ${value}`;
  };

  const openCompose = (mode, email) => {
    setComposeMode(mode);
    setComposeForm({
      to: mode === 'reply' ? extractEmailAddress(email.sender) : '',
      subject: mode === 'reply'
        ? buildReplySubject(email.subject)
        : buildForwardSubject(email.subject),
      body: '',
    });
  };

  const closeCompose = () => {
    setComposeMode(null);
    setComposeForm({ to: '', subject: '', body: '' });
  };

  const handleSendCompose = async (event) => {
    event.preventDefault();
    if (!selectedEmailFull || !composeMode) return;

    setSendingCompose(true);
    try {
      const endpoint = composeMode === 'reply' ? 'reply' : 'forward';
      const payload = {
        to: composeForm.to.trim(),
        subject: composeForm.subject.trim(),
        body: composeForm.body.trim(),
      };

      const res = await apiFetch(`/emails/${selectedEmailFull.id}/${endpoint}`, {
        method: 'POST',
        body: payload,
      });
      const data = await res.json();

      if (res.ok) {
        showNotification('success', data.message || 'Az e-mail sikeresen elküldve.');
        closeCompose();
      } else {
        showNotification('error', data.message || 'Nem sikerült elküldeni az e-mailt.');
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hálózati hiba az e-mail küldése során.');
    } finally {
      setSendingCompose(false);
    }
  };

  const handleDeleteEmail = async (email) => {
    if (!confirm('Biztosan törölni szeretnéd ezt az e-mailt az alkalmazásból? A Gmail postafiókban megmarad.')) return;

    try {
      const res = await apiFetch(`/emails/${email.id}`, { method: 'DELETE' });
      const data = await res.json();
      if (res.ok) {
        showNotification('success', data.message || 'Az e-mail sikeresen törölve.');
        fetchStats();
        fetchEmails();
        setSelectedEmail(null);
        setSelectedEmailFull(null);
      } else {
        showNotification('error', data.message || 'Hiba történt a törlés során.');
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hiba történt a törlés során.');
    }
  };

  const handleDeleteAccount = async (id) => {
    if (!confirm('Biztosan törölni szeretnéd ezt a Gmail fiókot és a hozzá tartozó összes levelet?')) return;
    
    try {
      const res = await apiFetch(`/accounts/${id}`, { method: 'DELETE' });
      if (res.ok) {
        showNotification('success', 'A Gmail fiók sikeresen törölve.');
        fetchAccounts();
        fetchStats();
        fetchEmails();
        if (selectedEmail && selectedEmail.gmail_account_id === id) {
          setSelectedEmail(null);
          setSelectedEmailFull(null);
        }
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hiba történt a törlés során.');
    }
  };

  const handleSyncAccount = async (account) => {
    setSyncingAccountId(account.id);
    try {
      const res = await apiFetch(`/accounts/${account.id}/sync`, { method: 'POST' });
      const data = await res.json();
      if (res.ok) {
        showNotification('success', data.message || 'Sikeres szinkronizálás!');
        fetchAccounts();
        fetchStats();
        fetchEmails();
      } else {
        showNotification('error', data.message || 'Hiba a szinkronizálás során.');
        fetchAccounts(); // Update status indicator
      }
    } catch (e) {
      console.error(e);
      showNotification('error', 'Nem sikerült kapcsolódni a szinkronizációs szolgáltatáshoz.');
    } finally {
      setSyncingAccountId(null);
    }
  };

  const handleTestConnection = async (account) => {
    setTestingAccountId(account.id);
    try {
      const res = await apiFetch(`/accounts/${account.id}/test`, { method: 'POST' });
      const data = await res.json();
      if (res.ok) {
        showNotification('success', data.message || 'Kapcsolat sikeres!');
      } else {
        showNotification('error', data.message || 'A kapcsolódás sikertelen.');
      }
      fetchAccounts();
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hálózati hiba a tesztelés során.');
    } finally {
      setTestingAccountId(null);
    }
  };

  // SVGs as inline components for clean self-containment
  const Icons = {
    Dashboard: () => (
      <svg className="nav-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
    ),
    Mail: () => (
      <svg className="nav-icon" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    ),
    Accounts: () => (
      <svg className="nav-icon" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    ),
    Users: () => (
      <svg className="nav-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    ),
    Sparkles: () => (
      <svg className="nav-icon" viewBox="0 0 24 24" style={{stroke: 'var(--primary)'}}><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/><path d="m19 12-4 4-4-4"/></svg>
    ),
    Refresh: ({ className = '' }) => (
      <svg className={`nav-icon ${className}`} viewBox="0 0 24 24"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
    ),
    Delete: () => (
      <svg className="nav-icon" style={{width: 16, height: 16}} viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
    ),
    Edit: () => (
      <svg className="nav-icon" style={{width: 16, height: 16}} viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
    ),
    Search: () => (
      <svg className="search-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    ),
    Check: () => (
      <svg viewBox="0 0 24 24" width="16" height="16" stroke="var(--success)" strokeWidth="3" fill="none" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    ),
    Paperclip: () => (
      <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
    )
  };

  const getPriorityLabel = (priority) => {
    switch (priority) {
      case 'urgent': return 'Sürgős';
      case 'high': return 'Magas';
      case 'medium': return 'Közepes';
      case 'low': return 'Alacsony';
      default: return priority;
    }
  };

  const getCategoryLabel = (cat) => {
    switch (cat) {
      case 'billing': return 'Pénzügy / Számla';
      case 'work': return 'Munka';
      case 'security': return 'Biztonság';
      case 'promotion': return 'Promóció';
      case 'spam': return 'Spam';
      case 'personal': return 'Személyes';
      default: return cat;
    }
  };

  const lastSyncAt = accounts.reduce((latest, account) => {
    if (!account.last_fetched_at) return latest;
    const timestamp = new Date(account.last_fetched_at).getTime();
    return timestamp > latest ? timestamp : latest;
  }, 0);

  const formatLastSync = (timestamp) => {
    if (!timestamp) return 'Még nem történt szinkronizálás';
    return new Date(timestamp).toLocaleString('hu-HU', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  };

  const formatFileSize = (bytes) => {
    const size = Number(bytes || 0);
    if (!size) return '';
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
  };

  const getAttachmentDownloadUrl = (emailId, attachmentId) =>
    `${API_BASE}/emails/${emailId}/attachments/${attachmentId}`;

  const handleDownloadAttachment = async (emailId, attachment) => {
    const downloadKey = `${emailId}-${attachment.id}`;
    setDownloadingAttachmentKey(downloadKey);

    try {
      const res = await apiFetch(getAttachmentDownloadUrl(emailId, attachment.id));
      if (!res.ok) {
        let message = 'Nem sikerült letölteni a mellékletet.';
        try {
          const data = await res.json();
          message = data.message || message;
        } catch (_) {
          // ignore non-json error responses
        }
        showNotification('error', message);
        return;
      }

      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = attachment.filename || 'attachment';
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (e) {
      console.error(e);
      showNotification('error', 'Hiba a melléklet letöltése során.');
    } finally {
      setDownloadingAttachmentKey(null);
    }
  };

  const openBillingInbox = (email = null) => {
    setFilterCategory('billing');
    setFilterAccount('');
    setFilterPriority('');
    setFilterSentiment('');
    setSearchQuery('');
    setCurrentPage(1);
    setActiveTab('inbox');
    if (email) {
      fetchEmailDetail(email);
    }
  };

  const getAutoReplyLabel = (email) => {
    switch (email.auto_reply_status) {
      case 'sent':
        return `Automatikus válasz elküldve: ${new Date(email.auto_replied_at).toLocaleString('hu-HU')}`;
      case 'skipped':
        return 'Automatikus válasz kihagyva';
      case 'failed':
        return 'Automatikus válasz sikertelen';
      case 'pending':
        return 'Automatikus válasz folyamatban...';
      default:
        return null;
    }
  };

  return (
    <div className="app-container">
      {/* Sidebar navigation */}
      <aside className="sidebar">
        <div className="logo-container">
          <div className="logo-icon">G</div>
          <span className="logo-text">Gmail Evaluator</span>
        </div>
        
        <nav>
          <ul className="nav-menu">
            <li 
              className={`nav-item ${activeTab === 'dashboard' ? 'active' : ''}`}
              onClick={() => setActiveTab('dashboard')}
            >
              <Icons.Dashboard />
              <span>Vezérlőpult</span>
            </li>
            <li 
              className={`nav-item ${activeTab === 'inbox' ? 'active' : ''}`}
              onClick={() => {
                setActiveTab('inbox');
                fetchEmails();
              }}
            >
              <Icons.Mail />
              <span>Intelligens Inbox</span>
            </li>
            <li 
              className={`nav-item ${activeTab === 'accounts' ? 'active' : ''}`}
              onClick={() => {
                setActiveTab('accounts');
                fetchAccounts();
              }}
            >
              <Icons.Accounts />
              <span>Gmail Fiókok</span>
            </li>
            <li 
              className={`nav-item ${activeTab === 'users' ? 'active' : ''}`}
              onClick={() => {
                setActiveTab('users');
                fetchUsers();
              }}
            >
              <Icons.Users />
              <span>Felhasználók</span>
            </li>
          </ul>
        </nav>

        <div className="sidebar-footer">
          <div className="status-dot pulsing"></div>
          <span>Környezet: Docker Local</span>
        </div>
      </aside>

      <div className="main-shell">
        <header className="topbar">
          <h1 className="topbar-title">{TAB_TITLES[activeTab]}</h1>
          <div className="topbar-right">
            <div className="app-header-sync">
              <Icons.Refresh className={loadingAccounts ? 'spinner' : ''} />
              <span>
                Utolsó szinkronizálás: <strong>{formatLastSync(lastSyncAt)}</strong>
              </span>
            </div>
            <div className="topbar-user">
              <span className="topbar-user-name">{user.name}</span>
              <button type="button" className="btn btn-secondary topbar-logout" onClick={onLogout}>
                Kijelentkezés
              </button>
            </div>
          </div>
        </header>

        <main className="main-content">
        {/* Banner notification */}
        {alert && (
          <div className={`alert alert-${alert.type}`}>
            {alert.type === 'success' && <Icons.Check />}
            <span>{alert.message}</span>
          </div>
        )}

        {/* Tab 1: Dashboard */}
        {activeTab === 'dashboard' && (
          <div>
            <div className="page-header">
              <div>
                <p className="page-subtitle">Összegzett e-mail elemzések és AI kiértékelések</p>
              </div>
              <button 
                className="btn btn-secondary"
                onClick={() => {
                  fetchStats();
                  fetchAccounts();
                  fetchEmails();
                  showNotification('success', 'Adatok frissítve!');
                }}
                disabled={loadingStats}
              >
                <Icons.Refresh className={loadingStats ? 'spinner' : ''} />
                <span>Frissítés</span>
              </button>
            </div>

            {/* Statistics Row */}
            <div className="stats-grid">
              <div className="kpi-card kpi-card--green">
                <span className="kpi-label">Összes levelezés</span>
                <span className="kpi-value">{stats ? stats.total_emails : 0}</span>
                <span className="kpi-sub">Aktív szinkronizáció</span>
              </div>
              <div className="kpi-card kpi-card--red">
                <span className="kpi-label">Sürgős levelek</span>
                <span className="kpi-value">
                  {stats ? (stats.priorities.urgent + stats.priorities.high) : 0}
                </span>
                <span className="kpi-sub">Azonnali figyelmet igényel</span>
              </div>
              <div className="kpi-card kpi-card--blue">
                <span className="kpi-label">Aktív Gmail fiókok</span>
                <span className="kpi-value">
                  {accounts ? accounts.filter(a => a.status === 'active').length : 0}
                </span>
                <span className="kpi-sub">Összesen: {accounts.length} fiók</span>
              </div>
            </div>

            {/* Charts and distributions Grid */}
            <div className="dashboard-grid">
              
              {/* Daily trend graph */}
              <div className="panel-dark chart-container">
                <div className="panel-dark-header">
                  <h3>E-mail forgalom trend (Elmúlt 7 nap)</h3>
                </div>
                <div className="panel-dark-body">
                <div className="bar-chart">
                  {stats && stats.trend ? stats.trend.map((day, idx) => {
                    const maxVal = Math.max(...stats.trend.map(d => d.count), 1);
                    const heightPercent = `${(day.count / maxVal) * 150}px`;
                    return (
                      <div className="bar-column" key={idx}>
                        <div className="bar-fill" style={{height: heightPercent}}>
                          <div className="bar-tooltip">{day.count} db</div>
                        </div>
                        <span className="bar-label">{day.label}</span>
                      </div>
                    );
                  }) : (
                    <div style={{color: '#9ca3af', width: '100%', textAlign: 'center', paddingBottom: '3rem'}}>
                      Nincs elég adat a grafikon kirajzolásához
                    </div>
                  )}
                </div>
                </div>
              </div>

              {/* Categories list distribution */}
              <div className="card">
                <h3 className="card-title">
                  Kategóriák szerinti eloszlás
                </h3>
                <div className="progress-list">
                  {stats ? Object.entries(stats.categories).map(([cat, count]) => {
                    const total = stats.total_emails || 1;
                    const percent = (count / total) * 100;
                    return (
                      <div className="progress-item" key={cat}>
                        <div className="progress-header">
                          <span className="progress-label">
                            <span className="progress-dot" style={{backgroundColor: `var(--cat-${cat})`}}></span>
                            {getCategoryLabel(cat)}
                          </span>
                          <span style={{fontWeight: 600}}>{count} levél ({percent.toFixed(0)}%)</span>
                        </div>
                        <div className="progress-track">
                          <div 
                            className="progress-bar" 
                            style={{width: `${percent}%`, backgroundColor: `var(--cat-${cat})`}}
                          ></div>
                        </div>
                      </div>
                    );
                  }) : <p style={{color: 'var(--text-muted)'}}>Betöltés...</p>}
                </div>
              </div>

            </div>

            <div className="card billing-panel">
              <div className="billing-panel-header">
                <div>
                  <h3 className="card-title billing-panel-title">
                    Pénzügy / Számla levelek
                  </h3>
                  <p className="billing-panel-subtitle">
                    {stats ? `${stats.categories.billing || 0} billing kategóriájú levél az adatbázisban` : 'Betöltés...'}
                  </p>
                </div>
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => openBillingInbox()}
                >
                  Összes megtekintése
                </button>
              </div>

              <div className="feed-list">
                {stats && stats.recent_billing && stats.recent_billing.length > 0 ? (
                  stats.recent_billing.map((email) => (
                    <div
                      className="feed-item billing-feed-item"
                      key={email.id}
                      onClick={() => openBillingInbox(email)}
                    >
                      <div className="feed-info">
                        <span className="feed-subject">{email.subject || '(Nincs tárgy)'}</span>
                        <span className="feed-sender">{email.sender}</span>
                        <span className="billing-feed-date">
                          {new Date(email.received_at).toLocaleString('hu-HU', {
                            year: 'numeric',
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                          })}
                        </span>
                      </div>
                      <div style={{ display: 'flex', gap: '0.5rem', flexShrink: 0 }}>
                        <span className="badge badge-category">{getCategoryLabel('billing')}</span>
                        {email.priority && (
                          <span className={`badge badge-priority-${email.priority}`}>
                            {getPriorityLabel(email.priority)}
                          </span>
                        )}
                      </div>
                    </div>
                  ))
                ) : (
                  <p style={{ color: 'var(--text-muted)', textAlign: 'center', padding: '2rem' }}>
                    Nincs pénzügyi vagy számla jellegű levél a szinkronizált levelek között.
                  </p>
                )}
              </div>
            </div>

            {/* Dashboard Bottom Row: Urgent Emails & Sentiment */}
            <div className="dashboard-grid">
              
              {/* Urgent Emails List */}
              <div className="card">
                <h3 className="card-title card-title--danger">
                  Sürgős és Magas Prioritású Levelek
                </h3>
                <div className="feed-list">
                  {stats && stats.recent_urgent && stats.recent_urgent.length > 0 ? (
                    stats.recent_urgent.map((email) => (
                      <div 
                        className="feed-item" 
                        key={email.id}
                        onClick={() => {
                          setActiveTab('inbox');
                          fetchEmailDetail(email);
                        }}
                      >
                        <div className="feed-info">
                          <span className="feed-subject">{email.subject}</span>
                          <span className="feed-sender">{email.sender}</span>
                        </div>
                        <div style={{display: 'flex', gap: '0.5rem'}}>
                          <span className={`badge badge-priority-${email.priority}`}>{getPriorityLabel(email.priority)}</span>
                          <span className="badge badge-category">{getCategoryLabel(email.category)}</span>
                        </div>
                      </div>
                    ))
                  ) : (
                    <p style={{color: 'var(--text-muted)', textAlign: 'center', padding: '2rem'}}>
                      Nincsenek sürgős vagy kiemelten kezelendő levelek!
                    </p>
                  )}
                </div>
              </div>

              {/* Sentiment Chart */}
              <div className="card" style={{display: 'flex', flexDirection: 'column', justifyContent: 'space-between'}}>
                <h3 className="card-title">
                  Hangulati Eloszlás (AI Sentiment)
                </h3>
                {stats ? (
                  <div className="progress-list" style={{padding: '1rem 0'}}>
                    {/* Positive */}
                    <div className="progress-item">
                      <div className="progress-header">
                        <span className="progress-label" style={{color: 'var(--success)'}}>Pozitív</span>
                        <span>{stats.sentiments.positive} db</span>
                      </div>
                      <div className="progress-track">
                        <div 
                          className="progress-bar" 
                          style={{width: `${(stats.sentiments.positive / (stats.total_emails || 1)) * 100}%`, backgroundColor: 'var(--success)'}}
                        ></div>
                      </div>
                    </div>
                    {/* Neutral */}
                    <div className="progress-item" style={{marginTop: '1rem'}}>
                      <div className="progress-header">
                        <span className="progress-label" style={{color: 'var(--text-secondary)'}}>Semleges</span>
                        <span>{stats.sentiments.neutral} db</span>
                      </div>
                      <div className="progress-track">
                        <div 
                          className="progress-bar" 
                          style={{width: `${(stats.sentiments.neutral / (stats.total_emails || 1)) * 100}%`, backgroundColor: 'var(--text-secondary)'}}
                        ></div>
                      </div>
                    </div>
                    {/* Negative */}
                    <div className="progress-item" style={{marginTop: '1rem'}}>
                      <div className="progress-header">
                        <span className="progress-label" style={{color: 'var(--danger)'}}>Negatív</span>
                        <span>{stats.sentiments.negative} db</span>
                      </div>
                      <div className="progress-track">
                        <div 
                          className="progress-bar" 
                          style={{width: `${(stats.sentiments.negative / (stats.total_emails || 1)) * 100}%`, backgroundColor: 'var(--danger)'}}
                        ></div>
                      </div>
                    </div>
                  </div>
                ) : <p style={{color: 'var(--text-muted)'}}>Betöltés...</p>}
              </div>

            </div>
          </div>
        )}

        {/* Tab 2: Smart Inbox E-mail Explorer */}
        {activeTab === 'inbox' && (
          <div>
            <p className="page-subtitle" style={{marginBottom: '1rem'}}>
              Összegyűjtött levelek mélyreható AI kiértékelése
            </p>

            {/* Filters Row */}
            <div className="filters-row card" style={{padding: '1rem'}}>
              <div className="search-input-wrapper">
                <Icons.Search />
                <input 
                  type="text" 
                  className="form-input search-input" 
                  placeholder="Keresés tárgyra, küldőre, tartalomra..." 
                  value={searchQuery}
                  onChange={(e) => {
                    setSearchQuery(e.target.value);
                    setCurrentPage(1);
                  }}
                />
              </div>

              {/* Account Filter */}
              <select 
                className="filter-select"
                value={filterAccount}
                onChange={(e) => {
                  setFilterAccount(e.target.value);
                  setCurrentPage(1);
                }}
              >
                <option value="">Összes Gmail fiók</option>
                {accounts.map(acc => (
                  <option key={acc.id} value={acc.id}>{acc.email}</option>
                ))}
              </select>

              {/* Category Filter */}
              <select 
                className="filter-select"
                value={filterCategory}
                onChange={(e) => {
                  setFilterCategory(e.target.value);
                  setCurrentPage(1);
                }}
              >
                <option value="">Összes kategória</option>
                <option value="billing">Pénzügy / Számla</option>
                <option value="work">Munka</option>
                <option value="security">Biztonság</option>
                <option value="promotion">Promóció</option>
                <option value="spam">Spam</option>
                <option value="personal">Személyes</option>
              </select>

              {/* Priority Filter */}
              <select 
                className="filter-select"
                value={filterPriority}
                onChange={(e) => {
                  setFilterPriority(e.target.value);
                  setCurrentPage(1);
                }}
              >
                <option value="">Összes prioritás</option>
                <option value="urgent">Sürgős</option>
                <option value="high">Magas</option>
                <option value="medium">Közepes</option>
                <option value="low">Alacsony</option>
              </select>

              {/* Sentiment Filter */}
              <select 
                className="filter-select"
                value={filterSentiment}
                onChange={(e) => {
                  setFilterSentiment(e.target.value);
                  setCurrentPage(1);
                }}
              >
                <option value="">Összes hangulat</option>
                <option value="positive">Pozitív</option>
                <option value="neutral">Semleges</option>
                <option value="negative">Negatív</option>
              </select>
            </div>

            {/* Split View Inbox */}
            <div className="inbox-layout">
              
              {/* Emails List Column */}
              <div className="inbox-sidebar">
                {loadingEmails && emails.length === 0 ? (
                  <p style={{textAlign: 'center', padding: '3rem', color: 'var(--text-secondary)'}}>
                    E-mailek betöltése...
                  </p>
                ) : emails.length > 0 ? (
                  <div className="inbox-list">
                    {emails.map((email) => (
                      <div 
                        className={`inbox-item ${selectedEmail && selectedEmail.id === email.id ? 'active' : ''}`}
                        key={email.id}
                        onClick={() => fetchEmailDetail(email)}
                      >
                        <div className="inbox-item-header">
                          <span className="inbox-item-subject">{email.subject}</span>
                          <span className="inbox-item-date">
                            {new Date(email.received_at).toLocaleDateString('hu-HU', {month: 'short', day: 'numeric'})}
                          </span>
                        </div>
                        <span className="inbox-item-sender">{email.sender}</span>
                        <div className="inbox-item-meta">
                          <span className={`badge badge-priority-${email.priority}`}>{getPriorityLabel(email.priority)}</span>
                          <span className={`badge badge-sentiment-${email.sentiment}`}>{email.sentiment}</span>
                          <span className="badge badge-category">{getCategoryLabel(email.category)}</span>
                        </div>
                      </div>
                    ))}

                    {/* Pagination */}
                    {totalPages > 1 && (
                      <div style={{display: 'flex', justifyContent: 'space-between', marginTop: '1rem', alignItems: 'center'}}>
                        <button 
                          className="btn btn-secondary" 
                          style={{padding: '0.4rem 0.8rem'}}
                          disabled={currentPage === 1}
                          onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
                        >
                          Előző
                        </button>
                        <span style={{fontSize: '0.8rem', color: 'var(--text-secondary)'}}>
                          {currentPage} / {totalPages} oldal
                        </span>
                        <button 
                          className="btn btn-secondary" 
                          style={{padding: '0.4rem 0.8rem'}}
                          disabled={currentPage === totalPages}
                          onClick={() => setCurrentPage(prev => Math.min(prev + 1, totalPages))}
                        >
                          Következő
                        </button>
                      </div>
                    )}
                  </div>
                ) : (
                  <p style={{textAlign: 'center', padding: '3rem', color: 'var(--text-muted)'}}>
                    Nem található a szűrésnek megfelelő e-mail.
                  </p>
                )}
              </div>

              {/* Email Detail Column */}
              <div className="card" style={{padding: 0, overflow: 'hidden'}}>
                {loadingDetail ? (
                  <div style={{display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: '100%', gap: '1rem'}}>
                    <div className="spinner" style={{width: 32, height: 32, borderTopColor: 'var(--primary)'}}></div>
                    <p style={{color: 'var(--text-secondary)'}}>Kiértékelési adatok letöltése...</p>
                  </div>
                ) : selectedEmailFull ? (
                  <div className="inbox-detail">
                    
                    {/* Header */}
                    <div className="detail-header">
                      <div className="detail-subject-row">
                        <h2 className="detail-subject">{selectedEmailFull.subject}</h2>
                        <div className="detail-actions">
                          <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => openCompose('reply', selectedEmailFull)}
                            title="Válasz küldése"
                          >
                            Válasz
                          </button>
                          <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => openCompose('forward', selectedEmailFull)}
                            title="E-mail továbbítása"
                          >
                            Továbbítás
                          </button>
                          <button
                            type="button"
                            className="btn btn-danger"
                            onClick={() => handleDeleteEmail(selectedEmailFull)}
                            title="E-mail törlése az alkalmazásból"
                          >
                            <Icons.Delete />
                            Törlés
                          </button>
                        </div>
                      </div>
                      
                      <div className="detail-meta-row">
                        <div className="detail-sender">
                          Küldő: <span>{selectedEmailFull.sender}</span>
                        </div>
                        <div>
                          Beérkezett: {new Date(selectedEmailFull.received_at).toLocaleString('hu-HU')}
                        </div>
                      </div>
                      <div style={{fontSize: '0.8rem', color: 'var(--text-muted)'}}>
                        Gmail Cím: {selectedEmailFull.gmail_account ? selectedEmailFull.gmail_account.email : ''}
                      </div>
                      {getAutoReplyLabel(selectedEmailFull) && (
                        <div style={{
                          marginTop: '0.75rem',
                          fontSize: '0.85rem',
                          color: selectedEmailFull.auto_reply_status === 'sent' ? 'var(--success)' : 'var(--text-secondary)'
                        }}>
                          {getAutoReplyLabel(selectedEmailFull)}
                        </div>
                      )}
                    </div>

                    {/* AI Evaluation Board */}
                    <div className="ai-eval-board">
                      <div className="ai-eval-title">
                        <svg width="20" height="20" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <span>Mesterséges Intelligencia (AI) Kiértékelés</span>
                      </div>

                      <div className="ai-metrics">
                        <div className="ai-metric-item">
                          <span className="ai-metric-label">Prioritás</span>
                          <div>
                            <span className={`badge badge-priority-${selectedEmailFull.priority}`}>
                              {getPriorityLabel(selectedEmailFull.priority)}
                            </span>
                          </div>
                        </div>
                        <div className="ai-metric-item">
                          <span className="ai-metric-label">Kategória</span>
                          <div>
                            <span className="badge badge-category">
                              {getCategoryLabel(selectedEmailFull.category)}
                            </span>
                          </div>
                        </div>
                        <div className="ai-metric-item">
                          <span className="ai-metric-label">Hangulat (Sentiment)</span>
                          <div>
                            <span className={`badge badge-sentiment-${selectedEmailFull.sentiment}`}>
                              {selectedEmailFull.sentiment}
                            </span>
                          </div>
                        </div>
                      </div>

                      <div className="ai-metric-item">
                        <span className="ai-metric-label">Rövid Összefoglaló</span>
                        <p className="ai-summary">{selectedEmailFull.summary}</p>
                      </div>

                      <div className="ai-metric-item">
                        <span className="ai-metric-label">Javasolt Teendők (Action Items)</span>
                        {selectedEmailFull.action_items && selectedEmailFull.action_items.length > 0 ? (
                          <ul className="action-items-list">
                            {selectedEmailFull.action_items.map((item, idx) => (
                              <li className="action-item-check" key={idx}>
                                <Icons.Check />
                                <span>{item}</span>
                              </li>
                            ))}
                          </ul>
                        ) : (
                          <p style={{color: 'var(--text-muted)', fontSize: '0.85rem'}}>Nincsenek kiolvasható azonnali teendők.</p>
                        )}
                      </div>
                    </div>

                    {selectedEmailFull.attachments && selectedEmailFull.attachments.length > 0 && (
                      <div className="email-attachments">
                        <span className="ai-metric-label">Mellékletek ({selectedEmailFull.attachments.length})</span>
                        <ul className="attachment-list">
                          {selectedEmailFull.attachments.map((attachment) => (
                            <li className="attachment-item" key={attachment.id}>
                              <div className="attachment-meta">
                                <Icons.Paperclip />
                                <div>
                                  <span className="attachment-name">{attachment.filename}</span>
                                  <span className="attachment-details">
                                    {attachment.mime_type}
                                    {attachment.size ? ` · ${formatFileSize(attachment.size)}` : ''}
                                  </span>
                                </div>
                              </div>
                              <button
                                type="button"
                                className="btn btn-secondary attachment-download"
                                onClick={() => handleDownloadAttachment(selectedEmailFull.id, attachment)}
                                disabled={downloadingAttachmentKey === `${selectedEmailFull.id}-${attachment.id}`}
                              >
                                {downloadingAttachmentKey === `${selectedEmailFull.id}-${attachment.id}`
                                  ? 'Letöltés...'
                                  : 'Letöltés'}
                              </button>
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}

                    {/* Original Mail Content */}
                    <div className="ai-metric-item">
                      <span className="ai-metric-label" style={{marginBottom: '0.5rem', display: 'block'}}>Eredeti e-mail tartalom</span>
                      <div className="email-original-body">
                        {selectedEmailFull.body || '(Üres e-mail törzs)'}
                      </div>
                    </div>

                  </div>
                ) : (
                  <div style={{display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: '100%', color: 'var(--text-muted)', padding: '2rem'}}>
                    <Icons.Mail />
                    <p style={{marginTop: '1rem'}}>Válassz ki egy e-mailt a listából a részletes AI kiértékelés megtekintéséhez.</p>
                  </div>
                )}
              </div>

            </div>
          </div>
        )}

        {/* Tab 3: Gmail Accounts Manager */}
        {activeTab === 'accounts' && (
          <div className="dashboard-grid" style={{gridTemplateColumns: '1.2fr 1fr'}}>
            
            {/* Accounts list and form */}
            <div>
              <p className="page-subtitle" style={{marginBottom: '1rem'}}>
                Add meg a követni kívánt e-mail címeket
              </p>

              {/* Add account card */}
              <div className="card" style={{marginBottom: '1.5rem'}}>
                <h3 className="card-title">
                  Új Gmail Fiók hozzáadása
                </h3>
                <form onSubmit={handleAddAccount}>
                  <div className="form-group">
                    <label className="form-label">Gmail E-mail Cím</label>
                    <input 
                      type="email" 
                      className="form-input" 
                      placeholder="példa@gmail.com" 
                      required
                      value={newAccount.email}
                      onChange={(e) => setNewAccount(prev => ({...prev, email: e.target.value}))}
                    />
                    {formErrors.email && <span className="form-error">{formErrors.email[0]}</span>}
                  </div>

                  <div className="form-group">
                    <label className="form-label">Gmail Alkalmazás-jelszó (App Password)</label>
                    <input 
                      type="password" 
                      className="form-input" 
                      placeholder="xxxx xxxx xxxx xxxx" 
                      required
                      value={newAccount.password}
                      onChange={(e) => setNewAccount(prev => ({...prev, password: e.target.value}))}
                    />
                    {formErrors.password && <span className="form-error">{formErrors.password[0]}</span>}
                  </div>

                  <button 
                    type="submit" 
                    className="btn btn-primary" 
                    style={{width: '100%', marginTop: '0.5rem'}}
                    disabled={submittingAccount}
                  >
                    {submittingAccount ? (
                      <>
                        <div className="spinner"></div>
                        <span>Kapcsolódás és ellenőrzés...</span>
                      </>
                    ) : (
                      <span>Hozzáadás és Kapcsolat Tesztelése</span>
                    )}
                  </button>
                </form>
              </div>

              {/* Active accounts list */}
              <div className="card">
                <h3 className="card-title">
                  Regisztrált Fiókok listája
                </h3>
                
                {loadingAccounts ? (
                  <p style={{color: 'var(--text-secondary)', textAlign: 'center', padding: '1rem'}}>Fiókok betöltése...</p>
                ) : accounts.length > 0 ? (
                  <div className="account-list">
                    {accounts.map(acc => (
                      <div className="account-card" key={acc.id}>
                        <div className="account-meta">
                          <div 
                            className="status-dot" 
                            style={{
                              backgroundColor: acc.status === 'active' ? 'var(--success)' : (acc.status === 'error' ? 'var(--danger)' : 'var(--warning)'),
                              boxShadow: `0 0 10px ${acc.status === 'active' ? 'var(--success)' : (acc.status === 'error' ? 'var(--danger)' : 'var(--warning)')}`
                            }}
                          ></div>
                          <div className="account-details">
                            <h4>{acc.email}</h4>
                            <p>
                              {acc.emails_count} levél letöltve |{' '}
                              {acc.last_fetched_at ? (
                                `Szinkronizálva: ${new Date(acc.last_fetched_at).toLocaleTimeString('hu-HU')}`
                              ) : (
                                'Soha nem szinkronizált'
                              )}
                            </p>
                            {acc.status === 'error' && (
                              <p style={{color: 'var(--danger)', fontSize: '0.75rem', marginTop: '0.25rem', maxWidth: '300px'}}>
                                Hiba: {acc.last_error || 'Sikertelen kapcsolódás.'}
                              </p>
                            )}
                          </div>
                        </div>

                        <div className="account-actions">
                          <button 
                            className="btn btn-secondary" 
                            style={{padding: '0.45rem 0.75rem', fontSize: '0.8rem'}}
                            onClick={() => handleSyncAccount(acc)}
                            disabled={syncingAccountId === acc.id}
                          >
                            <Icons.Refresh className={syncingAccountId === acc.id ? 'spinner' : ''} />
                            <span>Szinkron</span>
                          </button>
                          <button 
                            className="btn btn-secondary"
                            style={{padding: '0.45rem 0.75rem', fontSize: '0.8rem'}}
                            onClick={() => handleTestConnection(acc)}
                            disabled={testingAccountId === acc.id}
                          >
                            {testingAccountId === acc.id ? 'Teszt...' : 'Teszt'}
                          </button>
                          <button 
                            className="btn btn-danger"
                            style={{padding: '0.45rem 0.5rem'}}
                            onClick={() => handleDeleteAccount(acc.id)}
                          >
                            <Icons.Delete />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p style={{color: 'var(--text-muted)', textAlign: 'center', padding: '2rem'}}>
                    Még nem adtál meg Gmail fiókot.
                  </p>
                )}
              </div>

            </div>

            {/* Instruction manual setup guide */}
            <div className="card guide-container">
              <h3 className="card-title card-title--brand">
                Gmail Beállítási Útmutató
              </h3>
              <p style={{fontSize: '0.9rem', color: 'var(--text-secondary)', lineHeight: 1.5}}>
                A Gmail biztonsági szabályai miatt a rendes e-mail jelszavad nem használható külső alkalmazásokkal. A biztonságos kapcsolódáshoz hozz létre egy <strong>Alkalmazás-jelszót (App Password)</strong>:
              </p>

              <div className="guide-step">
                <div className="guide-step-num">1</div>
                <div className="guide-step-content">
                  <h4>Google Fiók Biztonság</h4>
                  <p>Lépj be a Google fiókodba, válaszd ki a <strong>Biztonság (Security)</strong> fület.</p>
                </div>
              </div>

              <div className="guide-step">
                <div className="guide-step-num">2</div>
                <div className="guide-step-content">
                  <h4>2-Lépcsős Azonosítás</h4>
                  <p>Győződj meg róla, hogy a <strong>2-lépcsős azonosítás (2-Step Verification)</strong> be van kapcsolva.</p>
                </div>
              </div>

              <div className="guide-step">
                <div className="guide-step-num">3</div>
                <div className="guide-step-content">
                  <h4>Alkalmazás-jelszavak</h4>
                  <p>Kattints a 2-lépcsős azonosítás menüponton belül az <strong>Alkalmazás-jelszavak (App Passwords)</strong> lehetőségre (a lap alján találod).</p>
                </div>
              </div>

              <div className="guide-step">
                <div className="guide-step-num">4</div>
                <div className="guide-step-content">
                  <h4>Létrehozás</h4>
                  <p>Adj meg egy nevet az alkalmazásnak (pl. <i>Gmail Evaluator</i>), majd kattints a <strong>Létrehozás</strong> gombra.</p>
                </div>
              </div>

              <div className="guide-step">
                <div className="guide-step-num">5</div>
                <div className="guide-step-content">
                  <h4>Másolás és Beillesztés</h4>
                  <p>A Google generál egy sárga mezőben lévő 16-karakteres kódot:</p>
                  <div className="guide-code">xxxx xxxx xxxx xxxx</div>
                  <p style={{marginTop: '0.25rem'}}>Ezt másold ki szóközökkel együtt, és illeszd be a bal oldali űrlap jelszó mezőjébe!</p>
                </div>
              </div>
            </div>

          </div>
        )}

        {activeTab === 'users' && (
          <div>
            <div className="page-header">
              <p className="page-subtitle">
                Regisztrált alkalmazás-felhasználók listája. A „Megerősítve” admin jóváhagyást jelent
                (a „Te” badge mutatja, kivel vagy belépve). Saját fiókodat is a Megerősítés gombbal aktiválhatod.
              </p>
              <button
                className="btn btn-secondary"
                onClick={fetchUsers}
                disabled={loadingUsers}
              >
                <Icons.Refresh className={loadingUsers ? 'spinner' : ''} />
                <span>Frissítés</span>
              </button>
            </div>

            <div className="card data-table-card">
              <h3 className="card-title">Felhasználók ({users.length})</h3>

              {loadingUsers ? (
                <p style={{ color: 'var(--text-secondary)', textAlign: 'center', padding: '2rem' }}>
                  Felhasználók betöltése...
                </p>
              ) : users.length > 0 ? (
                <div className="data-table-wrap">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Név</th>
                        <th>E-mail</th>
                        <th>Regisztráció</th>
                        <th>Státusz</th>
                        <th>Művelet</th>
                      </tr>
                    </thead>
                    <tbody>
                      {users.map((entry) => (
                        <tr key={entry.id} className={entry.id === user.id ? 'data-table-row--current' : ''}>
                          <td>
                            <span className="data-table-primary">{entry.name}</span>
                            {entry.id === user.id && (
                              <span className="badge badge-category" style={{ marginLeft: '0.5rem' }}>Te</span>
                            )}
                          </td>
                          <td>{entry.email}</td>
                          <td>
                            {new Date(entry.created_at).toLocaleString('hu-HU', {
                              year: 'numeric',
                              month: '2-digit',
                              day: '2-digit',
                              hour: '2-digit',
                              minute: '2-digit',
                            })}
                          </td>
                          <td>
                            <div className="data-table-status">
                              {entry.email_verified_at ? (
                                <span className="badge badge-sentiment-positive">Megerősítve</span>
                              ) : (
                                <span className="badge badge-sentiment-neutral">Nincs megerősítve</span>
                              )}
                            </div>
                          </td>
                          <td>
                            <div className="data-table-actions">
                              <button
                                type="button"
                                className="btn btn-secondary data-table-action"
                                onClick={() => openUserEdit(entry)}
                                disabled={savingUser || deletingUserId === entry.id || verifyingUserId === entry.id}
                              >
                                <Icons.Edit />
                                <span>Szerkesztés</span>
                              </button>
                              {!entry.email_verified_at && (
                                <button
                                  type="button"
                                  className="btn btn-primary data-table-action"
                                  onClick={() => handleVerifyUser(entry)}
                                  disabled={verifyingUserId === entry.id || deletingUserId === entry.id || savingUser}
                                >
                                  {verifyingUserId === entry.id ? 'Megerősítés...' : 'Megerősítés'}
                                </button>
                              )}
                              {entry.id !== user.id && (
                                <button
                                  type="button"
                                  className="btn btn-danger data-table-action"
                                  onClick={() => handleDeleteUser(entry)}
                                  disabled={deletingUserId === entry.id || verifyingUserId === entry.id || savingUser}
                                  title="Felhasználó törlése"
                                >
                                  {deletingUserId === entry.id ? (
                                    'Törlés...'
                                  ) : (
                                    <>
                                      <Icons.Delete />
                                      <span>Törlés</span>
                                    </>
                                  )}
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p style={{ color: 'var(--text-muted)', textAlign: 'center', padding: '2rem' }}>
                  Még nincs regisztrált felhasználó.
                </p>
              )}
            </div>
          </div>
        )}

      </main>

      {editingUser && (
        <div className="compose-overlay" onClick={closeUserEdit}>
          <div className="compose-modal card" onClick={(event) => event.stopPropagation()}>
            <div className="compose-header">
              <h3>Felhasználó szerkesztése</h3>
              <button type="button" className="compose-close" onClick={closeUserEdit} disabled={savingUser} aria-label="Bezárás">
                ×
              </button>
            </div>

            <form onSubmit={handleSaveUser}>
              <div className="form-group">
                <label className="form-label" htmlFor="edit-user-name">Név</label>
                <input
                  id="edit-user-name"
                  type="text"
                  className="form-input"
                  required
                  value={userEditForm.name}
                  onChange={(event) => setUserEditForm((prev) => ({ ...prev, name: event.target.value }))}
                />
                {userEditErrors.name && <span className="form-error">{userEditErrors.name[0]}</span>}
              </div>

              <div className="form-group">
                <label className="form-label" htmlFor="edit-user-email">E-mail</label>
                <input
                  id="edit-user-email"
                  type="email"
                  className="form-input"
                  required
                  value={userEditForm.email}
                  onChange={(event) => setUserEditForm((prev) => ({ ...prev, email: event.target.value }))}
                />
                {userEditErrors.email && <span className="form-error">{userEditErrors.email[0]}</span>}
              </div>

              <div className="form-group">
                <label className="form-label" htmlFor="edit-user-password">Új jelszó (opcionális)</label>
                <input
                  id="edit-user-password"
                  type="password"
                  className="form-input"
                  autoComplete="new-password"
                  value={userEditForm.password}
                  onChange={(event) => setUserEditForm((prev) => ({ ...prev, password: event.target.value }))}
                />
                {userEditErrors.password && <span className="form-error">{userEditErrors.password[0]}</span>}
              </div>

              {userEditForm.password && (
                <div className="form-group">
                  <label className="form-label" htmlFor="edit-user-password-confirmation">Jelszó megerősítése</label>
                  <input
                    id="edit-user-password-confirmation"
                    type="password"
                    className="form-input"
                    autoComplete="new-password"
                    value={userEditForm.password_confirmation}
                    onChange={(event) => setUserEditForm((prev) => ({ ...prev, password_confirmation: event.target.value }))}
                  />
                </div>
              )}

              <div className="compose-actions">
                <button type="button" className="btn btn-secondary" onClick={closeUserEdit} disabled={savingUser}>
                  Mégse
                </button>
                <button type="submit" className="btn btn-primary" disabled={savingUser}>
                  {savingUser ? 'Mentés...' : 'Mentés'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {composeMode && selectedEmailFull && (
        <div className="compose-overlay" onClick={closeCompose}>
          <div className="compose-modal card" onClick={(event) => event.stopPropagation()}>
            <div className="compose-header">
              <h3>{composeMode === 'reply' ? 'Válasz küldése' : 'E-mail továbbítása'}</h3>
              <button type="button" className="compose-close" onClick={closeCompose} disabled={sendingCompose} aria-label="Bezárás">
                ×
              </button>
            </div>

            <form onSubmit={handleSendCompose}>
              <div className="form-group">
                <label className="form-label">Feladó</label>
                <input
                  type="text"
                  className="form-input"
                  value={selectedEmailFull.gmail_account ? selectedEmailFull.gmail_account.email : ''}
                  disabled
                />
              </div>

              <div className="form-group">
                <label className="form-label">Címzett</label>
                <input
                  type="email"
                  className="form-input"
                  placeholder="cimzett@example.com"
                  required
                  value={composeForm.to}
                  onChange={(event) => setComposeForm((prev) => ({ ...prev, to: event.target.value }))}
                />
              </div>

              <div className="form-group">
                <label className="form-label">Tárgy</label>
                <input
                  type="text"
                  className="form-input"
                  required
                  value={composeForm.subject}
                  onChange={(event) => setComposeForm((prev) => ({ ...prev, subject: event.target.value }))}
                />
              </div>

              <div className="form-group">
                <label className="form-label">
                  {composeMode === 'reply' ? 'Válasz szövege' : 'Megjegyzés (opcionális)'}
                </label>
                <textarea
                  className="form-input compose-textarea"
                  rows={8}
                  required={composeMode === 'reply'}
                  placeholder={
                    composeMode === 'reply'
                      ? 'Írd ide a választ...'
                      : 'Ide írhatsz rövid megjegyzést a továbbított levél elé...'
                  }
                  value={composeForm.body}
                  onChange={(event) => setComposeForm((prev) => ({ ...prev, body: event.target.value }))}
                />
              </div>

              {composeMode === 'forward' && (
                <p className="compose-hint">
                  A továbbított levél tartalma automatikusan hozzá lesz fűzve az üzenethez.
                </p>
              )}

              <div className="compose-actions">
                <button type="button" className="btn btn-secondary" onClick={closeCompose} disabled={sendingCompose}>
                  Mégse
                </button>
                <button type="submit" className="btn btn-primary" disabled={sendingCompose}>
                  {sendingCompose ? 'Küldés...' : 'Küldés'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      </div>
    </div>
  );
}
