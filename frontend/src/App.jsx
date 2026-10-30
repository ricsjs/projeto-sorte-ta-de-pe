import Loja from './Loja.jsx'
import Painel from './Painel.jsx'

// o painel nao tem link na loja: o operador abre direto por /painel
const noPainel = window.location.pathname.replace(/\/+$/, '') === '/painel'

export default function App() {
  return (
    <>
      <nav>
        <span className="brand">
          <img src="/logo.png" alt="A Sorte Tá de Pé" width="480" height="417" />
        </span>
      </nav>
      <main>{noPainel ? <Painel /> : <Loja />}</main>
    </>
  )
}
