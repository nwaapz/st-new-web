import { useEffect, type ReactNode } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { CartScreen } from './screens/CartScreen';
import { ChequesScreen } from './screens/ChequesScreen';
import { DiscountsScreen } from './screens/DiscountsScreen';
import { FestivalScreen } from './screens/FestivalScreen';
import { FiltersScreen } from './screens/FiltersScreen';
import { LoginScreen } from './screens/LoginScreen';
import { MessagesScreen } from './screens/MessagesScreen';
import { OrderScreen } from './screens/OrderScreen';
import { PriceSheetScreen } from './screens/PriceSheetScreen';
import { ProductScreen } from './screens/ProductScreen';
import { ProfileScreen } from './screens/ProfileScreen';
import { ReportsScreen } from './screens/ReportsScreen';
import { SeriesScreen } from './screens/SeriesScreen';
import { SetupScreen } from './screens/SetupScreen';
import { ShopScreen } from './screens/ShopScreen';
import { setupPush } from './push';
import { useApp } from './state/AppState';

function Gate({ children }: { children: ReactNode }) {
  const { bootstrapped, user, setupDone } = useApp();
  const loc = useLocation();
  if (!bootstrapped) return <div className="empty">در حال آماده‌سازی…</div>;
  if (!user) return <Navigate to="/login" replace />;
  if (!setupDone && loc.pathname !== '/setup') return <Navigate to="/setup" replace />;
  return <>{children}</>;
}

export function App() {
  const { user } = useApp();
  useEffect(() => {
    if (user) void setupPush();
  }, [user]);

  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/shop" replace /> : <LoginScreen />} />
      <Route path="/setup" element={<Gate><SetupScreen /></Gate>} />
      <Route path="/shop" element={<Gate><ShopScreen /></Gate>} />
      <Route path="/shop/filters" element={<Gate><FiltersScreen /></Gate>} />
      <Route path="/product/:productId" element={<Gate><ProductScreen /></Gate>} />
      <Route path="/series/:seriesId" element={<Gate><SeriesScreen /></Gate>} />
      <Route path="/cart" element={<Gate><CartScreen /></Gate>} />
      <Route path="/reports" element={<Gate><ReportsScreen /></Gate>} />
      <Route path="/orders/:orderId" element={<Gate><OrderScreen /></Gate>} />
      <Route path="/messages" element={<Gate><MessagesScreen /></Gate>} />
      <Route path="/discounts" element={<Gate><DiscountsScreen /></Gate>} />
      <Route path="/festival" element={<Gate><FestivalScreen /></Gate>} />
      <Route path="/price-sheet" element={<Gate><PriceSheetScreen /></Gate>} />
      <Route path="/cheques" element={<Gate><ChequesScreen /></Gate>} />
      <Route path="/profile" element={<Gate><ProfileScreen /></Gate>} />
      <Route path="*" element={<Navigate to="/shop" replace />} />
    </Routes>
  );
}
