// Dijalankan otomatis oleh image mongo saat volume masih kosong.
// Koleksi untuk chatbot rekomendasi CINÉLOG.
//
// chat_sessions : { user_id, title, created_at, updated_at }
// chat_messages : { session_id, role: "user"|"bot", text, intent, sparql, results_count, created_at }
//   - "sparql" menyimpan query SPARQL yang dihasilkan chatbot (bahan bukti untuk paper/presentasi)
//   - created_at HARUS bertipe Date agar TTL berfungsi

const chat = db.getSiblingDB('cinelog_chat');

chat.createCollection('chat_sessions');
chat.createCollection('chat_messages');

chat.chat_sessions.createIndex({ user_id: 1, updated_at: -1 });
chat.chat_messages.createIndex({ session_id: 1, created_at: 1 });
// Pesan otomatis dihapus setelah 90 hari
chat.chat_messages.createIndex({ created_at: 1 }, { expireAfterSeconds: 60 * 60 * 24 * 90 });
