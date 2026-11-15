import { openDB, type IDBPDatabase } from 'idb';

const DB_NAME = 'startech-sales-pwa';
const STORE = 'kv';

let dbPromise: Promise<IDBPDatabase> | null = null;

function db() {
  if (!dbPromise) {
    dbPromise = openDB(DB_NAME, 1, {
      upgrade(database) {
        if (!database.objectStoreNames.contains(STORE)) {
          database.createObjectStore(STORE);
        }
      },
    });
  }
  return dbPromise;
}

export async function cacheGet<T>(key: string): Promise<T | undefined> {
  const database = await db();
  return (await database.get(STORE, key)) as T | undefined;
}

export async function cacheSet(key: string, value: unknown): Promise<void> {
  const database = await db();
  await database.put(STORE, value, key);
}

export async function cacheDel(key: string): Promise<void> {
  const database = await db();
  await database.delete(STORE, key);
}
