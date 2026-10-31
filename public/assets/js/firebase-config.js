// public/assets/js/firebase-config.js
// Firebase Web SDK v10+ configuration & client initialization
import { initializeApp } from "https://www.gstatic.com/firebasejs/10.12.0/firebase-app.js";
import { 
  getAuth, 
  signInWithPopup, 
  GoogleAuthProvider, 
  signInWithEmailAndPassword,
  createUserWithEmailAndPassword,
  signOut,
  onAuthStateChanged 
} from "https://www.gstatic.com/firebasejs/10.12.0/firebase-auth.js";
import { 
  getFirestore, 
  doc, 
  getDoc, 
  setDoc, 
  collection, 
  getDocs, 
  addDoc,
  query,
  where,
  onSnapshot 
} from "https://www.gstatic.com/firebasejs/10.12.0/firebase-firestore.js";

// Production Firebase Project Configuration
export const firebaseConfig = {
  apiKey: "AIzaSyAL57hgjqzv0JlZxhmpMAURn-mgTUAYkEQ",
  authDomain: "web-hackathon-b7b1a.firebaseapp.com",
  projectId: "web-hackathon-b7b1a",
  storageBucket: "web-hackathon-b7b1a.firebasestorage.app",
  messagingSenderId: "293963808835",
  appId: "1:293963808835:web:d6f35e71402703201054f6",
  measurementId: "G-KG76YLN37N"
};

// Initialize Firebase
const app = initializeApp(firebaseConfig);
const auth = getAuth(app);
const db = getFirestore(app);
const googleProvider = new GoogleAuthProvider();
googleProvider.setCustomParameters({ prompt: 'select_account' });

export { 
  app, 
  auth, 
  db, 
  googleProvider, 
  signInWithPopup, 
  GoogleAuthProvider, 
  signInWithEmailAndPassword,
  createUserWithEmailAndPassword,
  signOut, 
  onAuthStateChanged, 
  doc, 
  getDoc, 
  setDoc, 
  collection, 
  getDocs, 
  addDoc,
  query,
  where,
  onSnapshot 
};
