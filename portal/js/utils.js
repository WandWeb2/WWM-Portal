// --- HELPERS ---
const arrayMove = (arr, from, to) => {
    const res = Array.from(arr);
    const [removed] = res.splice(from, 1);
    res.splice(to, 0, removed);
    return res;
};

// Export for Node.js testing environment, but don't break browser environment
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { arrayMove };
}
