import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { registerSW } from 'virtual:pwa-register';
import { App } from './App';
import { AppStateProvider } from './state/AppState';
import './index.css';

registerSW({
  immediate: true,
  onRegisteredSW(_url, registration) {
    if (!registration?.active || navigator.serviceWorker.controller) return;
    if (sessionStorage.getItem('sales-sw-claimed') === '1') return;
    sessionStorage.setItem('sales-sw-claimed', '1');
    window.location.reload();
  },
});

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter basename="/test2/sales">
      <AppStateProvider>
        <App />
      </AppStateProvider>
    </BrowserRouter>
  </StrictMode>,
);
