// public/assets/js/firestore-data.js
// Firebase-Native Firestore Database Layer (Option A)
// Manages real-time data sync for rooms, sessions, booking requests, and solved schedules.

import { 
  db, 
  collection, 
  doc, 
  getDoc, 
  getDocs, 
  setDoc, 
  addDoc, 
  onSnapshot 
} from "/assets/js/firebase-config.js";

export const FirestoreDB = {
  // 1. One-time initial seeding from cit_public.json into Firestore collections
  async seedInitialDataIfNeeded() {
    if (!db) return;
    try {
      const metaCheck = await getDoc(doc(db, "meta", "dataset_info"));
      if (metaCheck.exists()) {
        console.log("Firestore already seeded with canonical CIT dataset.");
        return;
      }

      console.log("Seeding Firestore with cit_public.json starter pack...");
      const res = await fetch("/assets/cit_public.json");
      if (!res.ok) return;
      const data = await res.json();

      // Seed meta info
      await setDoc(doc(db, "meta", "dataset_info"), {
        dataset_id: data.dataset_id || "cit_public.json",
        institute: data.institute || "Chilika Institute of Technology",
        seeded_at: new Date().toISOString()
      });

      // Seed rooms
      if (Array.isArray(data.rooms)) {
        for (const room of data.rooms) {
          await setDoc(doc(db, "rooms", room.id), room);
        }
      }

      // Seed faculty
      if (Array.isArray(data.faculty)) {
        for (const f of data.faculty) {
          await setDoc(doc(db, "faculty", f.id), f);
        }
      }

      // Seed booking requests
      if (Array.isArray(data.booking_requests)) {
        for (const req of data.booking_requests) {
          await setDoc(doc(db, "booking_requests", req.id), {
            ...req,
            status: req.status || "approved"
          });
        }
      }

      // Seed sessions
      if (Array.isArray(data.sessions)) {
        for (const s of data.sessions) {
          await setDoc(doc(db, "sessions", s.id), s);
        }
      }

      console.log("Firestore seeding completed successfully!");
    } catch (e) {
      console.warn("Firestore seeding notice:", e.message);
    }
  },

  // 2. Fetch Rooms directly from Firestore
  async getRooms() {
    if (!db) return [];
    try {
      const snap = await getDocs(collection(db, "rooms"));
      return snap.docs.map(d => d.data());
    } catch (e) {
      console.warn("Error fetching rooms from Firestore:", e.message);
      return [];
    }
  },

  // 3. Fetch Booking Requests directly from Firestore
  async getBookingRequests() {
    if (!db) return [];
    try {
      const snap = await getDocs(collection(db, "booking_requests"));
      return snap.docs.map(d => d.data());
    } catch (e) {
      console.warn("Error fetching booking requests:", e.message);
      return [];
    }
  },

  // 4. Submit a new Booking Request to Firestore
  async createBookingRequest(reqData) {
    if (!db) throw new Error("Firestore not initialized");
    const reqRef = doc(collection(db, "booking_requests"));
    const newDoc = {
      id: reqData.id || "BR-" + Date.now().toString().slice(-4),
      title: reqData.title,
      category: reqData.category || "club",
      room_id: reqData.room_id,
      day: reqData.day,
      start_period: reqData.start_period,
      end_period: reqData.end_period,
      expected_attendance: reqData.expected_attendance || 50,
      status: "pending",
      submitted_at: new Date().toISOString()
    };
    await setDoc(reqRef, newDoc);
    return newDoc;
  },

  // 5. Real-time listener for summary stats
  subscribeSummary(callback) {
    if (!db) return () => {};
    return onSnapshot(collection(db, "booking_requests"), (snapshot) => {
      const requests = snapshot.docs.map(d => d.data());
      const approved = requests.filter(r => r.status === "approved").length;
      const pending = requests.filter(r => r.status === "pending").length;
      const rejected = requests.filter(r => r.status === "rejected").length;

      callback({
        totalRequests: requests.length,
        approved,
        pending,
        rejected,
        requests
      });
    });
  }
};

// Auto-run seeder when imported
FirestoreDB.seedInitialDataIfNeeded();

