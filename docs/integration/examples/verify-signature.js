// Vérifie la signature d'un webhook Core (Node.js ≥ 18, aucune dépendance).
// Avec Express, lisez le corps BRUT : app.post('/webhooks/core',
// express.raw({ type: 'application/json' }), (req, res) => { ... req.body.toString('utf8') ... })
const crypto = require('crypto');

function verifyCoreSignature(secret, rawBody, timestamp, signature, toleranceSeconds = 300) {
  // 1. Rejeter un message trop ancien (anti-rejeu).
  if (!/^\d+$/.test(timestamp || '')) return false;
  if (Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp)) > toleranceSeconds) return false;

  // 2. Recalculer la signature sur "<timestamp>.<corps brut>".
  const expected = crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex');

  // 3. Comparer en temps constant.
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(signature || '', 'utf8');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

module.exports = { verifyCoreSignature };

// Exécutable en ligne de commande (utilisé par les tests de cette doc) :
//   WEBHOOK_SECRET=… X_TIMESTAMP=… X_SIGNATURE=… node verify-signature.js < body.json
if (require.main === module) {
  const chunks = [];
  process.stdin.on('data', (c) => chunks.push(c));
  process.stdin.on('end', () => {
    const ok = verifyCoreSignature(
      process.env.WEBHOOK_SECRET || '',
      Buffer.concat(chunks).toString('utf8'),
      process.env.X_TIMESTAMP || '',
      process.env.X_SIGNATURE || '',
    );
    console.log(ok ? 'valid' : 'invalid');
    process.exit(ok ? 0 : 1);
  });
}
