import { useState } from 'react'
import Loja from './Loja.jsx'
import Painel from './Painel.jsx'

export default function App() {
  const [tela, setTela] = useState('loja')
  return (
    <>
      <nav>
        <span className="brand">
          <svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="var(--accent)" /><circle cx="22" cy="11" r="4.5" fill="var(--sun)" /><path d="M5 22c4-3 7-3 11 0s7 3 11 0" fill="none" stroke="#fff" strokeWidth="2.4" strokeLinecap="round" /></svg>
          Roteiro da Sorte
        </span>
        <div className="seg">
          <button className={tela === 'loja' ? 'on' : ''} onClick={() => setTela('loja')}>Loja</button>
          <button className={tela === 'painel' ? 'on' : ''} onClick={() => setTela('painel')}>Painel do operador</button>
        </div>
      </nav>
      <main>{tela === 'loja' ? <Loja /> : <Painel />}</main>
    </>
  )
}
