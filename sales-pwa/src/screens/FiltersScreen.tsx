import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';

export function FiltersScreen() {
  const { factories, categories, carModels, loadFilters } = useApp();
  const nav = useNavigate();
  const [factoryId, setFactoryId] = useState('');
  const [carModelId, setCarModelId] = useState('');
  const [categoryIds, setCategoryIds] = useState<number[]>([]);

  useEffect(() => {
    void loadFilters();
  }, [loadFilters]);

  const models = carModels.filter((m) => !factoryId || String(m.factory_id || '') === factoryId);

  return (
    <Shell footer={false}>
      <h1 className="h1">فیلترها</h1>
      <label className="muted">کارخانه</label>
      <select className="input" value={factoryId} onChange={(e) => setFactoryId(e.target.value)}>
        <option value="">همه</option>
        {factories.map((f) => (
          <option key={f.id} value={f.id}>{f.name}</option>
        ))}
      </select>
      <label className="muted">مدل خودرو</label>
      <select className="input" value={carModelId} onChange={(e) => setCarModelId(e.target.value)}>
        <option value="">همه</option>
        {models.map((m) => (
          <option key={m.id} value={m.id}>{m.name}</option>
        ))}
      </select>
      <label className="muted">دسته‌ها</label>
      <div className="chip-row">
        {categories.map((c) => {
          const on = categoryIds.includes(c.id);
          return (
            <button
              key={c.id}
              type="button"
              className={`chip ${on ? 'on' : ''}`}
              onClick={() => setCategoryIds(on ? categoryIds.filter((id) => id !== c.id) : [...categoryIds, c.id])}
            >
              {c.name}
            </button>
          );
        })}
      </div>
      <button
        className="btn"
        type="button"
        onClick={() => {
          const sp = new URLSearchParams();
          if (factoryId) sp.set('factory_id', factoryId);
          if (carModelId) sp.set('car_model_id', carModelId);
          if (categoryIds.length) sp.set('category_ids', categoryIds.join(','));
          nav(`/shop?${sp.toString()}`);
        }}
      >
        اعمال فیلتر
      </button>
    </Shell>
  );
}
