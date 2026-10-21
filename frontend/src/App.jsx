import { useState } from 'react'
import Loja from './Loja.jsx'
import Painel from './Painel.jsx'

export default function App() {
  const [tela, setTela] = useState('loja')
  return (
    <>
      <nav>
        <span className="brand">
          <img src="/logo.png" alt="A Sorte Tá de Pé" width="480" height="417" />
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
