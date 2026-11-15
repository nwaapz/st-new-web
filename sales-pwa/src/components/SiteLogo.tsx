import { useEffect, useMemo, useState } from 'react';
import { cachedGet } from '../api/client';
import { CacheKeys } from '../cache/keys';
import { mediaUrl } from '../utils/media';

const PWA_ICON = `${import.meta.env.BASE_URL}icons/pwa-192.png`;

function candidates(cmsPath?: string | null): string[] {
  const list = [
    cmsPath ? mediaUrl(cmsPath) : '',
    '/test2/images/logo.png',
    '/test2/images/jumpingLogo.png',
    PWA_ICON,
  ].filter(Boolean);
  return [...new Set(list)];
}

export function SiteLogo({ className = 'logo', alt = 'StarTech' }: { className?: string; alt?: string }) {
  const [cms, setCms] = useState<string | null>(null);
  const [index, setIndex] = useState(0);
  const srcs = useMemo(() => candidates(cms), [cms]);
  const src = srcs[Math.min(index, srcs.length - 1)] || PWA_ICON;

  useEffect(() => {
    void cachedGet<{ logo_image?: string | null }>('site-logo.php', CacheKeys.siteLogo()).then((r) => {
      setCms(r.data.logo_image || null);
      setIndex(0);
    });
  }, []);

  return (
    <span className="logo-wrap">
      <img
        className={className}
        src={src}
        alt={alt}
        onError={() => setIndex((i) => Math.min(i + 1, srcs.length - 1))}
      />
    </span>
  );
}
