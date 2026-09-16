import express, { Request, Response } from 'express';
import cors from 'cors';
import dotenv from 'dotenv';

// Load environment variables (db passwords, ports, secrets) securely
dotenv.config();

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware for parsing JSON data and enabling Cross-Origin requests
app.use(cors());
app.use(express.json());

// Foundational base route to verify our server is alive and kicking
app.get('/api/health', (req: Request, res: Response) => {
  res.json({
    status: 'healthy',
    version: '2.0.0',
    message: 'MMIT-OPS v2 Backend Core is operational!'
  });
});

// Start the server listening process
app.listen(PORT, () => {
  console.log(`🚀 Modernized backend running smoothly on port ${PORT}`);
});
